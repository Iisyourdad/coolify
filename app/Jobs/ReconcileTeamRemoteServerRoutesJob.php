<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ReconcileTeamRemoteServerRoutesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public int $teamId)
    {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(): void
    {
        Application::query()
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $this->teamId))
            ->chunkById(100, fn (Collection $resources) => $resources->each(fn (Application $resource) => SyncRemoteServerRouteJob::dispatch($resource)));

        Service::query()
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $this->teamId))
            ->chunkById(100, fn (Collection $resources) => $resources->each(fn (Service $resource) => SyncRemoteServerRouteJob::dispatch($resource)));
    }
}
