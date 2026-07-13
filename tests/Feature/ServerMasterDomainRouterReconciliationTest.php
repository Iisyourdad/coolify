<?php

use App\Enums\ProxyTypes;
use App\Jobs\ReconcileTeamEdgeProxyJob;
use App\Jobs\SyncApplicationEdgeProxyJob;
use App\Jobs\SyncServiceEdgeProxyJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues team reconciliation when master domain routing is enabled or disabled', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    Queue::fake();

    $server->settings->update(['is_master_domain_router_enabled' => true]);

    Queue::assertPushed(ReconcileTeamEdgeProxyJob::class, fn (ReconcileTeamEdgeProxyJob $job) => $job->teamId === $team->id);

    Queue::fake();

    $server->settings->update(['is_master_domain_router_enabled' => false]);

    Queue::assertPushed(ReconcileTeamEdgeProxyJob::class, fn (ReconcileTeamEdgeProxyJob $job) => $job->teamId === $team->id);
});

it('queues one team reconciliation when the master domain router switches servers', function () {
    $team = Team::factory()->create();
    $firstServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $secondServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $firstServer->settings->update(['is_master_domain_router_enabled' => true]);

    Queue::fake();

    $secondServer->settings->update(['is_master_domain_router_enabled' => true]);

    expect($firstServer->settings->fresh()->is_master_domain_router_enabled)->toBeFalse()
        ->and($secondServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue();
    Queue::assertPushed(ReconcileTeamEdgeProxyJob::class, 1);
    Queue::assertPushed(ReconcileTeamEdgeProxyJob::class, fn (ReconcileTeamEdgeProxyJob $job) => $job->teamId === $team->id);
});

it('does not reconcile a team for unrelated server setting changes', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    Queue::fake();

    $server->settings->update(['is_metrics_enabled' => ! $server->settings->is_metrics_enabled]);

    Queue::assertNotPushed(ReconcileTeamEdgeProxyJob::class);
});

it('queues team reconciliation when a server routing endpoint changes', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'ip' => '192.0.2.10',
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    Queue::fake();

    $server->update(['ip' => '192.0.2.11']);

    Queue::assertPushed(ReconcileTeamEdgeProxyJob::class, fn (ReconcileTeamEdgeProxyJob $job) => $job->teamId === $team->id);
});

it('fans team reconciliation out to only that teams applications and services', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $application = createApplicationForMasterRouterReconciliationTest($team);
    $otherApplication = createApplicationForMasterRouterReconciliationTest($otherTeam);
    $service = createServiceForMasterRouterReconciliationTest($team);
    $otherService = createServiceForMasterRouterReconciliationTest($otherTeam);

    Queue::fake();

    $job = new ReconcileTeamEdgeProxyJob($team->id);

    expect($job->queue)->toBe('high')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([5, 15, 30, 60]);

    $job->handle();

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, 1);
    Queue::assertPushed(
        SyncApplicationEdgeProxyJob::class,
        fn (SyncApplicationEdgeProxyJob $job) => $job->application->is($application)
            && ! $job->application->is($otherApplication)
    );
    Queue::assertPushed(SyncServiceEdgeProxyJob::class, 1);
    Queue::assertPushed(
        SyncServiceEdgeProxyJob::class,
        fn (SyncServiceEdgeProxyJob $job) => $job->service->is($service)
            && ! $job->service->is($otherService)
    );
});

function createApplicationForMasterRouterReconciliationTest(Team $team): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://'.new_public_id().'.example.com',
    ]);
}

function createServiceForMasterRouterReconciliationTest(Team $team): Service
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'server_id' => $server->id,
    ]);
}
