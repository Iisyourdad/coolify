<?php

namespace App\Actions\Service;

use App\Exceptions\EdgeProxyCleanupPendingException;
use App\Models\Server;
use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;

class DeleteService
{
    public function cleanupRemote(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations): void
    {
        $server = $this->resolveServer($service);
        $remoteCleanupException = null;

        try {
            if ($deleteVolumes && $server?->isFunctional()) {
                $commands = [];
                foreach ($service->applications()->get() as $application) {
                    foreach ($application->persistentStorages()->get() as $storage) {
                        $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                    }
                }
                foreach ($service->databases()->get() as $database) {
                    foreach ($database->persistentStorages()->get() as $storage) {
                        $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                    }
                }
                foreach ($commands as $command) {
                    $this->runRemoteCommands([$command], $server, false);
                }
            }

            if ($deleteConnectedNetworks && $server instanceof Server) {
                $this->deleteConnectedNetworks($service, $server);
            }
            if ($deleteConfigurations && $server instanceof Server) {
                $this->deleteConfigurations($service, $server);
            }
            if ($server instanceof Server) {
                $this->runRemoteCommands(['docker rm -f '.escapeshellarg($service->uuid)], $server, throwError: false);
            }
        } catch (\Throwable $exception) {
            $remoteCleanupException = $exception;
        }

        $edgeCleanupFailures = $this->cleanupEdgeProxyState($service);
        if ($edgeCleanupFailures !== []) {
            throw new EdgeProxyCleanupPendingException('service', $service->uuid, $edgeCleanupFailures);
        }

        if ($remoteCleanupException instanceof \Throwable) {
            throw new \RuntimeException($remoteCleanupException->getMessage(), previous: $remoteCleanupException);
        }
    }

    public function deleteLocal(Service $service): void
    {
        foreach ($service->applications()->get() as $application) {
            $application->forceDelete();
        }
        foreach ($service->databases()->get() as $database) {
            $database->forceDelete();
        }
        foreach ($service->scheduled_tasks as $task) {
            $task->delete();
        }
        $service->environment_variables()->delete();
        $service->tags()->detach();
        $service->forceDelete();
    }

    protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
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
        $serviceUuid = escapeshellarg($service->uuid);

        $this->runRemoteCommands([
            "docker network disconnect {$serviceUuid} coolify-proxy",
            "docker network rm {$serviceUuid}",
        ], $server, false);
    }

    protected function deleteConfigurations(Service $service, Server $server): void
    {
        $workdir = $service->workdir();
        if (! str($workdir)->endsWith($service->uuid)) {
            return;
        }

        $this->runRemoteCommands(['rm -rf '.escapeshellarg($workdir)], $server, false);
    }

    /** @return array<int, string> */
    protected function cleanupEdgeProxyState(Service $service): array
    {
        try {
            $failures = [
                ...app(EdgeProxyRemoteRouteService::class)->deleteService($service),
                ...app(EdgeProxyRemotePortForwardService::class)->deleteService($service),
            ];
        } catch (\Throwable $exception) {
            $failures = ['Unexpected edge proxy cleanup failure: '.$exception->getMessage()];
        }

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
}
