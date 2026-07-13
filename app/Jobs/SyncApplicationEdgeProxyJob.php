<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Server;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncApplicationEdgeProxyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public bool $deleteWhenMissingModels = true;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-application-edge-proxy-'.$this->application->uuid))->releaseAfter(5)->expireAfter(120)];
    }

    public function __construct(
        public Application $application,
        public ?Server $deploymentServer = null,
    ) {
        $this->onQueue('high');
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(
        EdgeProxyRemoteRouteService $routeService,
        EdgeProxyRemotePortForwardService $portForwardService,
    ): void {
        $failures = [];

        try {
            $routeWarnings = $this->deploymentServer instanceof Server
                ? $routeService->syncApplicationOnDeploymentServer($this->application, $this->deploymentServer)
                : $routeService->syncApplication($this->application);
            foreach ($routeWarnings as $warning) {
                $this->logWarning("Edge proxy routing warning for application {$this->application->uuid}: {$warning}");
            }
        } catch (\Throwable $exception) {
            $this->logWarning("Failed to sync edge proxy route for application {$this->application->uuid}: {$exception->getMessage()}");
            $failures[] = $exception;
        }

        try {
            $portForwardWarnings = $this->deploymentServer instanceof Server
                ? $portForwardService->syncApplicationOnDeploymentServer($this->application, $this->deploymentServer)
                : $portForwardService->syncApplication($this->application);
            foreach ($portForwardWarnings as $warning) {
                $this->logWarning("Edge port forwarding warning for application {$this->application->uuid}: {$warning}");
            }
        } catch (\Throwable $exception) {
            $this->logWarning("Failed to sync edge port forwarding for application {$this->application->uuid}: {$exception->getMessage()}");
            $failures[] = $exception;
        }

        if ($failures !== []) {
            throw $failures[0];
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->logWarning(
            "Application edge proxy sync permanently failed for {$this->application->uuid}: ".($exception?->getMessage() ?? 'unknown error')
        );
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
