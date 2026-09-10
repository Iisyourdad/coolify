<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Service;
use App\Services\RemoteServerRouteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncRemoteServerRouteJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $timeout = 120;

    public function __construct(public Application|Service $resource)
    {
        $this->onQueue('high');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('remote-route-'.$this->resource->getMorphClass().'-'.$this->resource->uuid))->shared()->releaseAfter(15)->expireAfter(150)];
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(RemoteServerRouteService $routes): void
    {
        if ($this->resource instanceof Application) {
            $routes->syncApplication($this->resource);

            return;
        }

        $routes->syncService($this->resource);
    }
}
