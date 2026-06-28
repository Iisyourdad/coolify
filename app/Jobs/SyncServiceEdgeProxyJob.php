<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncServiceEdgeProxyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-service-edge-proxy-'.$this->service->uuid))->expireAfter(120)->dontRelease()];
    }

    public function __construct(public Service $service)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            $routeWarnings = app(EdgeProxyRemoteRouteService::class)->syncService($this->service);
            foreach ($routeWarnings as $warning) {
                $this->logWarning("Edge proxy routing warning for service {$this->service->uuid}: {$warning}");
            }
        } catch (\Throwable $exception) {
            $this->logWarning("Failed to sync edge proxy route for service {$this->service->uuid}: {$exception->getMessage()}");
        }

        try {
            $portForwardWarnings = app(EdgeProxyRemotePortForwardService::class)->syncService($this->service);
            foreach ($portForwardWarnings as $warning) {
                $this->logWarning("Edge port forwarding warning for service {$this->service->uuid}: {$warning}");
            }
        } catch (\Throwable $exception) {
            $this->logWarning("Failed to sync edge port forwarding for service {$this->service->uuid}: {$exception->getMessage()}");
        }
    }

    protected function logWarning(string $message): void
    {
        if (app()->bound('log')) {
            app('log')->warning($message);

            return;
        }

        error_log($message);
    }
}
