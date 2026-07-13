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

class ReconcileTeamEdgeProxyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public int $teamId)
    {
        $this->onQueue('high');
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(): void
    {
        Application::query()
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $this->teamId))
            ->chunkById(100, function (Collection $applications): void {
                $applications->each(fn (Application $application) => SyncApplicationEdgeProxyJob::dispatch($application));
            });

        Service::query()
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $this->teamId))
            ->chunkById(100, function (Collection $services): void {
                $services->each(fn (Service $service) => SyncServiceEdgeProxyJob::dispatch($service));
            });
    }
}
