<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Exceptions\EdgeProxyCleanupPendingException;
use App\Models\Server;
use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations, bool $dockerCleanup)
    {
        $server = $this->resolveServer($service);

        try {
            if ($deleteVolumes && $server?->isFunctional()) {
                $storagesToDelete = collect([]);

                $service->environment_variables()->delete();
                $commands = [];
                foreach ($service->applications()->get() as $application) {
                    $storages = $application->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($service->databases()->get() as $database) {
                    $storages = $database->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($storagesToDelete as $storage) {
                    $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                }

                // Execute volume deletion first, this must be done first otherwise volumes will not be deleted.
                if (! empty($commands)) {
                    foreach ($commands as $command) {
                        $result = $this->runRemoteCommands([$command], $server, false);
                        if ($result !== null && $result !== 0) {
                            $this->logError('Error deleting volumes: '.$result);
                        }
                    }
                }
            }

            if ($deleteConnectedNetworks && $server instanceof Server) {
                $this->deleteConnectedNetworks($service, $server);
            }

            if ($server instanceof Server) {
                $this->runRemoteCommands(["docker rm -f $service->uuid"], $server, throwError: false);
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException($exception->getMessage(), previous: $exception);
        }

        $edgeCleanupFailures = $this->cleanupEdgeProxyState($service);
        if ($edgeCleanupFailures !== []) {
            throw new EdgeProxyCleanupPendingException('service', $service->uuid, $edgeCleanupFailures);
        }

        if ($deleteConfigurations && $server instanceof Server) {
            $this->deleteConfigurations($service, $server);
        }
        foreach ($service->applications()->get() as $application) {
            $application->forceDelete();
        }
        foreach ($service->databases()->get() as $database) {
            $database->forceDelete();
        }
        foreach ($service->scheduled_tasks as $task) {
            $task->delete();
        }
        $service->tags()->detach();
        $service->forceDelete();

        if ($dockerCleanup && $server instanceof Server) {
            CleanupDocker::dispatch($server, false, false);
        }
    }

    protected function runRemoteCommands(array $commands, $server, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $server, $throwError);
    }

    protected function resolveServer(Service $service): ?Server
    {
        $server = data_get($service, 'server') ?? data_get($service, 'destination.server');

        return $server instanceof Server ? $server : null;
    }

    protected function deleteConnectedNetworks(Service $service, Server $server): void
    {
        $this->runRemoteCommands([
            "docker network disconnect {$service->uuid} coolify-proxy",
            "docker network rm {$service->uuid}",
        ], $server, false);
    }

    protected function deleteConfigurations(Service $service, Server $server): void
    {
        $workdir = $service->workdir();
        if (! str($workdir)->endsWith($service->uuid)) {
            return;
        }

        $this->runRemoteCommands(['rm -rf '.$workdir], $server, false);
    }

    protected function cleanupEdgeProxyState(Service $service): array
    {
        $failures = [
            ...app(EdgeProxyRemoteRouteService::class)->deleteService($service),
            ...app(EdgeProxyRemotePortForwardService::class)->deleteService($service),
        ];

        foreach ($failures as $failure) {
            $this->logWarning($failure);
        }

        return $failures;
    }

    protected function logWarning(string $message, array $context = []): void
    {
        if (app()->bound('log')) {
            app('log')->warning($message, $context);

            return;
        }

        error_log($message.($context === [] ? '' : ' '.json_encode($context)));
    }

    protected function logError(string $message, array $context = []): void
    {
        if (app()->bound('log')) {
            app('log')->error($message, $context);

            return;
        }

        error_log($message.($context === [] ? '' : ' '.json_encode($context)));
    }
}
