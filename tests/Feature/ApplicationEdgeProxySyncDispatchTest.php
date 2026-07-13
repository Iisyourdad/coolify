<?php

use App\Enums\ProxyTypes;
use App\Jobs\SyncApplicationEdgeProxyJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('queues master route creation when a remote application is created with a domain', function () {
    $team = Team::factory()->create();
    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $masterServer->settings->update(['is_master_domain_router_enabled' => true]);

    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $deploymentServer->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    Queue::fake();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://new-app.example.com',
    ]);

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, function (SyncApplicationEdgeProxyJob $job) use ($application) {
        return $job->application->is($application) && is_null($job->deploymentServer);
    });
});

it('queues master route reconciliation when a remote application domain changes', function () {
    $team = Team::factory()->create();
    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $masterServer->settings->update(['is_master_domain_router_enabled' => true]);

    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $deploymentServer->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    Queue::fake();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => null,
    ]);

    expect($application->fqdn)->toBeNull();
    Queue::assertNotPushed(SyncApplicationEdgeProxyJob::class);
    Queue::fake();

    $application->update(['fqdn' => 'https://changed-app.example.com']);

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, function (SyncApplicationEdgeProxyJob $job) use ($application) {
        return $job->application->is($application) && is_null($job->deploymentServer);
    });
});

it('does not queue master route reconciliation for unrelated application changes', function () {
    $team = Team::factory()->create();
    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $masterServer->settings->update(['is_master_domain_router_enabled' => true]);

    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $deploymentServer->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    Queue::fake();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://existing-app.example.com',
    ]);

    Queue::fake();

    $application->update(['name' => 'renamed-application']);

    Queue::assertNotPushed(SyncApplicationEdgeProxyJob::class);
});
