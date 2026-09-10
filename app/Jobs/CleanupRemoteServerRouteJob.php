<?php

namespace App\Jobs;

use App\Services\RemoteServerRouteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class CleanupRemoteServerRouteJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 10;

    public $timeout = 120;

    public function __construct(public int $teamId, public string $resourceType, public string $resourceUuid)
    {
        $this->onQueue('high');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("remote-route-cleanup-{$this->resourceType}-{$this->resourceUuid}"))->shared()->releaseAfter(30)->expireAfter(150)];
    }

    public function backoff(): array
    {
        return [15, 30, 60, 120, 300];
    }

    public function handle(RemoteServerRouteService $routes): void
    {
        $routes->cleanup($this->teamId, $this->resourceType, $this->resourceUuid);
    }
}
