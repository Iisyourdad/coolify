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

    public $tries = 0;

    public $timeout = 36000;

    public bool $deleteWhenMissingModels = true;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-service-edge-proxy-'.$this->service->uuid))->releaseAfter(30)->expireAfter(36600)];
    }

    public function __construct(public Service $service)
    {
        $this->onQueue('high');
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(
        EdgeProxyRemoteRouteService $routeService,
        EdgeProxyRemotePortForwardService $portForwardService,
    ): void {
        $failures = [];

        try {
            $routeWarnings = $routeService->syncService($this->service);
            foreach ($routeWarnings as $warning) {
                $this->logWarning("Edge proxy routing warning for service {$this->service->uuid}: {$warning}");
                if ($this->warningRequiresRetry($warning)) {
                    $failures[] = new \RuntimeException($warning);
                }
            }
        } catch (\Throwable $exception) {
            $this->logWarning("Failed to sync edge proxy route for service {$this->service->uuid}: {$exception->getMessage()}");
            $failures[] = $exception;
        }

        try {
            $portForwardWarnings = $portForwardService->syncService($this->service);
            foreach ($portForwardWarnings as $warning) {
                $this->logWarning("Edge port forwarding warning for service {$this->service->uuid}: {$warning}");
                if ($this->warningRequiresRetry($warning)) {
                    $failures[] = new \RuntimeException($warning);
                }
            }
        } catch (\Throwable $exception) {
            $this->logWarning("Failed to sync edge port forwarding for service {$this->service->uuid}: {$exception->getMessage()}");
            $failures[] = $exception;
        }

        if ($failures !== []) {
            throw $failures[0];
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->logWarning(
            "Service edge proxy sync permanently failed for {$this->service->uuid}: ".($exception?->getMessage() ?? 'unknown error')
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

    private function warningRequiresRetry(string $warning): bool
    {
        $warning = strtolower(trim($warning));

        return str_starts_with($warning, 'failed to ')
            || str_contains($warning, 'partially applied');
    }
}
