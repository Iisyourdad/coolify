<?php

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\SyncApplicationEdgeProxyJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues edge proxy resync instead of syncing inline when restart count increases', function () {
    Queue::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'running:healthy',
        'restart_count' => 0,
    ]);

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncApplication')->never();
    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('syncApplication')->never();
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $containers = collect([
        [
            'Config' => [
                'Labels' => "coolify.applicationId={$application->id},coolify.pullRequestId=0,com.docker.compose.service=app",
            ],
            'State' => [
                'Status' => 'running',
                'Health' => [
                    'Status' => 'healthy',
                ],
            ],
            'RestartCount' => 2,
        ],
    ]);

    $action = new GetContainersStatus;
    $action->handle($server->fresh(), $containers, new Collection);

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, function (SyncApplicationEdgeProxyJob $job) use ($application) {
        return $job->application->is($application);
    });

    $application->refresh();

    expect($application->restart_count)->toBe(2)
        ->and($application->last_restart_type)->toBe('crash');
});
