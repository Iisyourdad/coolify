<?php

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\DeleteService;
use App\Actions\Service\StopService;
use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\EdgeProxyCleanupPendingException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteResourceJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 10;

    public function __construct(
        public Application|ApplicationPreview|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource,
        public bool $deleteVolumes = true,
        public bool $deleteConnectedNetworks = true,
        public bool $deleteConfigurations = true,
        public bool $dockerCleanup = true
    ) {
        if ($this->resource instanceof Application) {
            $this->tries = 0;
        }

        $this->onQueue('high');
    }

    public function backoff(): array
    {
        return isDev() ? [1, 5, 15] : [60, 300, 900, 1800, 3600];
    }

    public function middleware(): array
    {
        if ($this->resource instanceof Application) {
            return [(new WithoutOverlapping('application-edge-proxy-'.$this->resource->uuid))->shared()->releaseAfter(30)->expireAfter(36600)];
        }

        return [(new WithoutOverlapping($this->deletionLockKey()))->expireAfter(3600)->dontRelease()];
    }

    public function handle(): void
    {
        if ($this->resource instanceof ApplicationPreview) {
            DB::transaction(function (): void {
                $this->deleteApplicationPreview();
            });

            return;
        }

        $this->markResourcePendingDeletion();

        try {
            if ($this->resource instanceof Service) {
                $this->stopAndDeleteServiceResource();
            } else {
                $this->prepareResourceForDeletion();
            }
        } catch (EdgeProxyCleanupPendingException $exception) {
            $this->retryPendingEdgeCleanup($exception);

            return;
        } catch (\Throwable $exception) {
            $this->logRemoteCleanupFailure($exception);
        }

        if ($this->resource instanceof Application) {
            $edgeCleanupFailures = $this->cleanupApplicationEdgeProxyState($this->resource);
            if ($edgeCleanupFailures !== []) {
                $this->retryPendingEdgeCleanup(new EdgeProxyCleanupPendingException(
                    'application',
                    $this->resource->uuid,
                    $edgeCleanupFailures,
                ));

                return;
            }
        }

        $this->deleteLocalResource();
        $this->queueStuckedResourcesCleanup();
    }

    protected function deleteLocalResource(): void
    {
        DB::transaction(function (): void {
            try {
                $this->deleteScheduledVolumeBackups();
            } catch (\Throwable $e) {
                Log::warning('Remote backup cleanup failed while deleting resource; continuing with local deletion.', [
                    'resource_id' => $this->resource->id,
                    'resource_type' => $this->resource->type(),
                    'error' => $e->getMessage(),
                ]);
            }

            if ($this->resource instanceof Service) {
                app(DeleteService::class)->deleteLocal($this->resource);

                return;
            }

            if ($this->deleteVolumes) {
                $this->resource->persistentStorages()->delete();
            }
            $this->resource->fileStorages()->delete();

            if ($this->isDatabase()) {
                $this->resource->sslCertificates()->delete();
                $this->resource->scheduledBackups()->delete();
                $this->resource->tags()->detach();
            }
            $this->resource->environment_variables()->delete();
            $this->resource->forceDelete();
        });
    }

    private function isDatabase(): bool
    {
        return $this->resource instanceof StandalonePostgresql
            || $this->resource instanceof StandaloneRedis
            || $this->resource instanceof StandaloneMongodb
            || $this->resource instanceof StandaloneMysql
            || $this->resource instanceof StandaloneMariadb
            || $this->resource instanceof StandaloneKeydb
            || $this->resource instanceof StandaloneDragonfly
            || $this->resource instanceof StandaloneClickhouse;
    }

    protected function stopAndDeleteServiceResource(): void
    {
        StopService::run($this->resource, $this->deleteConnectedNetworks, $this->dockerCleanup);
        app(DeleteService::class)->cleanupRemote(
            $this->resource,
            $this->deleteVolumes,
            $this->deleteConnectedNetworks,
            $this->deleteConfigurations,
        );
    }

    protected function prepareResourceForDeletion(): void
    {
        switch ($this->resource->type()) {
            case 'application':
                StopApplication::run($this->resource, previewDeployments: true, dockerCleanup: $this->dockerCleanup);
                break;
            case 'standalone-postgresql':
            case 'standalone-redis':
            case 'standalone-mongodb':
            case 'standalone-mysql':
            case 'standalone-mariadb':
            case 'standalone-keydb':
            case 'standalone-dragonfly':
            case 'standalone-clickhouse':
                StopDatabase::run($this->resource, dockerCleanup: $this->dockerCleanup);
                break;
        }

        if ($this->deleteConfigurations) {
            $this->resource->deleteConfigurations();
        }
        if ($this->deleteVolumes) {
            $this->resource->deleteVolumes();
        }
        if ($this->deleteConnectedNetworks && $this->resource instanceof Application) {
            $this->resource->deleteConnectedNetworks();
        }
    }

    /** @return array<int, string> */
    protected function cleanupApplicationEdgeProxyState(Application $application): array
    {
        try {
            $failures = [
                ...app(EdgeProxyRemoteRouteService::class)->deleteApplication($application),
                ...app(EdgeProxyRemotePortForwardService::class)->deleteApplication($application),
            ];
        } catch (\Throwable $exception) {
            $failures = ['Unexpected edge proxy cleanup failure: '.$exception->getMessage()];
        }

        foreach ($failures as $failure) {
            $this->logWarning($failure);
        }

        return $failures;
    }

    protected function logWarning(string $message): void
    {
        if (app()->bound('log')) {
            app('log')->warning($message);

            return;
        }

        error_log($message);
    }

    protected function logRemoteCleanupFailure(\Throwable $exception): void
    {
        Log::warning('Remote cleanup failed while deleting resource; continuing with local deletion.', [
            'resource_id' => $this->resource->id,
            'resource_type' => $this->resource->type(),
            'error' => $exception->getMessage(),
        ]);
    }

    protected function queueStuckedResourcesCleanup(): void
    {
        CleanupStuckedResourcesJob::dispatchIfNotQueued();
    }

    protected function retryPendingEdgeCleanup(EdgeProxyCleanupPendingException $exception): void
    {
        $this->queueStuckedResourcesCleanup();

        if (! $this->shouldReleasePendingEdgeCleanup()) {
            throw $exception;
        }

        $this->release($this->pendingEdgeCleanupBackoff());
    }

    protected function shouldReleasePendingEdgeCleanup(): bool
    {
        return isset($this->job);
    }

    protected function pendingEdgeCleanupBackoff(): int
    {
        $backoff = array_values($this->backoff());
        if ($backoff === []) {
            return 0;
        }

        $attemptIndex = max($this->attempts() - 1, 0);

        return $backoff[min($attemptIndex, count($backoff) - 1)];
    }

    protected function markResourcePendingDeletion(): void
    {
        if (! ($this->resource instanceof Application || $this->resource instanceof Service)) {
            return;
        }

        if (! method_exists($this->resource, 'trashed') || $this->resource->trashed()) {
            return;
        }

        $this->resource->delete();
    }

    protected function deletionLockKey(): string
    {
        $resourceIdentifier = data_get($this->resource, 'uuid') ?? data_get($this->resource, 'id') ?? spl_object_id($this->resource);

        return 'delete-resource-'.$this->resource->getMorphClass().'-'.$resourceIdentifier;
    }

    private function deleteScheduledVolumeBackups(): void
    {
        if (! $this->resource->exists) {
            return;
        }

        $server = data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
        $resources = $this->resource instanceof Service
            ? $this->resource->applications()->get()->concat($this->resource->databases()->get())
            : collect([$this->resource]);

        foreach ($resources as $resource) {
            $storages = $resource->persistentStorages()->get()->concat($resource->fileStorages()->get());

            foreach ($storages as $storage) {
                foreach ($storage->scheduledBackups()->get() as $backup) {
                    DeleteScheduledVolumeBackup::run($backup, $server);
                }
            }
        }
    }

    private function deleteApplicationPreview()
    {
        $application = $this->resource->application;
        $server = $application->destination->server;
        $pull_request_id = $this->resource->pull_request_id;

        // Ensure the preview is soft deleted (may already be done in Livewire component)
        if (! $this->resource->trashed()) {
            $this->resource->delete();
        }

        // Cancel any active deployments for this PR (same logic as API cancel_deployment)
        $activeDeployments = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->where('pull_request_id', $pull_request_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->get();

        $cancelledDeployments = 0;

        foreach ($activeDeployments as $activeDeployment) {
            try {
                // Mark deployment as cancelled
                $activeDeployment->update([
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ]);
                $cancelledDeployments++;

                // Add cancellation log entry
                $activeDeployment->addLogEntry('Deployment cancelled: Pull request closed.', 'stderr');

                // Check if helper container exists and kill it
                $deployment_uuid = $activeDeployment->deployment_uuid;
                $escapedDeploymentUuid = escapeshellarg($deployment_uuid);
                $checkCommand = "docker ps -a --filter name={$escapedDeploymentUuid} --format '{{.Names}}'";
                $containerExists = instant_remote_process([$checkCommand], $server);

                if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                    instant_remote_process(["docker rm -f {$escapedDeploymentUuid}"], $server);
                    $activeDeployment->addLogEntry('Deployment container stopped.');
                } else {
                    $activeDeployment->addLogEntry('Helper container not yet started. Deployment will be cancelled when job checks status.');
                }

            } catch (\Throwable $e) {
                // Silently handle errors during deployment cancellation
            }
        }

        if ($cancelledDeployments > 0) {
            try {
                next_after_cancel($server);
            } catch (\Throwable $e) {
                \Log::warning("Failed to advance deployment queue after deleting preview {$this->resource->id}: {$e->getMessage()}");
            }
        }

        try {
            if ($server->isSwarm()) {
                $escapedStackName = escapeshellarg("{$application->uuid}-{$pull_request_id}");
                instant_remote_process(["docker stack rm {$escapedStackName}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $application->id, $pull_request_id)->toArray();
                $this->stopPreviewContainers($containers, $server);
            }
        } catch (\Throwable $e) {
            // Log the error but don't fail the job
            $this->logWarning('Error stopping preview containers for application '.$application->uuid.', PR #'.$pull_request_id.': '.$e->getMessage());
        }

        // Finally, force delete to trigger resource cleanup
        $this->resource->forceDelete();
    }

    private function stopPreviewContainers(array $containers, $server, int $timeout = 30)
    {
        if (empty($containers)) {
            return;
        }

        $containerNames = [];
        foreach ($containers as $container) {
            $containerNames[] = str_replace('/', '', $container['Names']);
        }

        $containerList = implode(' ', array_map('escapeshellarg', $containerNames));
        $commands = [
            dockerStopCommand($timeout, $containerList, $server),
            "docker rm -f $containerList",
        ];
        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }
}
