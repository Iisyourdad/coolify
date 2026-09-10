<?php

namespace App\Jobs;

use App\Exceptions\RemotePortForwardingConflictException;
use App\Models\Application;
use App\Models\Service;
use App\Services\RemoteServerRouteService;
use App\Services\RemoteServerPortForwardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRemoteServerRouteJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $timeout = 120;

    public function __construct(public Application|Service $resource, public ?int $deploymentServerId = null)
    {
        $this->onQueue('default');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('remote-route-'.$this->resource->getMorphClass().'-'.$this->resource->uuid))->shared()->releaseAfter(15)->expireAfter(150)];
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(RemoteServerRouteService $routes, RemoteServerPortForwardService $forwards): void
    {
        if ($this->resource instanceof Application) {
            $server = $this->validatedApplicationDeploymentServer($this->resource);
            $routes->syncApplication($this->resource, $server);
            $this->syncPortForwarder(fn () => $forwards->syncApplication($this->resource, $server));

            return;
        }

        $routes->syncService($this->resource);
        $this->syncPortForwarder(fn () => $forwards->syncService($this->resource));
    }

    private function syncPortForwarder(callable $sync): void
    {
        try {
            $sync();
        } catch (RemotePortForwardingConflictException $exception) {
            // HTTP routing has already synchronized. A port conflict or an
            // unavailable optional stream forwarder must not monopolize workers
            // or block application deployments; later lifecycle events retry it.
            Log::warning('Remote TCP/UDP forwarder synchronization was deferred.', [
                'resource_type' => $this->resource->getMorphClass(),
                'resource_uuid' => $this->resource->uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function validatedApplicationDeploymentServer(Application $application): ?\App\Models\Server
    {
        if ($this->deploymentServerId === null) return null;
        $application->loadMissing('destination.server', 'additional_servers');
        $allowed = collect([$application->destination?->server])->merge($application->additional_servers);
        return $allowed->first(fn ($server) => $server?->id === $this->deploymentServerId);
    }
}
