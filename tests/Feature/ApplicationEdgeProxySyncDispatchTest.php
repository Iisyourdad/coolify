<?php

use App\Enums\ProxyTypes;
use App\Jobs\SyncApplicationEdgeProxyJob;
use App\Jobs\SyncServiceEdgeProxyJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('queues master route creation when a remote application is created with a domain', function () {
    $team = Team::factory()->create();
    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    Queue::fake();

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

it('queues application route reconciliation when noindex or redirect changes', function () {
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
        'fqdn' => 'https://existing-app.example.com,https://www.existing-app.example.com',
        'redirect' => 'both',
    ]);

    Queue::fake();

    $application->update(['noindex_domains' => ['https://existing-app.example.com']]);

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, fn (SyncApplicationEdgeProxyJob $job) => $job->application->is($application));

    Queue::fake();

    $application->update(['redirect' => 'www']);

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, fn (SyncApplicationEdgeProxyJob $job) => $job->application->is($application));
});

it('queues service route reconciliation when service application noindex or redirect changes', function () {
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

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $deploymentServer->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $serviceApplication = ServiceApplication::query()->create([
        'service_id' => $service->id,
        'name' => 'web',
        'fqdn' => 'https://service.example.com,https://www.service.example.com',
        'redirect' => 'both',
    ]);

    Queue::fake();

    $serviceApplication->update(['noindex_domains' => ['https://service.example.com']]);

    Queue::assertPushed(SyncServiceEdgeProxyJob::class, fn (SyncServiceEdgeProxyJob $job) => $job->service->is($service));

    Queue::fake();

    $serviceApplication->update(['redirect' => 'www']);

    Queue::assertPushed(SyncServiceEdgeProxyJob::class, fn (SyncServiceEdgeProxyJob $job) => $job->service->is($service));
});

it('queues master route reconciliation when routing settings change', function () {
    $team = Team::factory()->create();
    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    Queue::fake();

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
        'fqdn' => 'https://settings-app.example.com',
    ]);

    Queue::fake();

    $application->settings->update(['is_force_https_enabled' => false]);

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, fn (SyncApplicationEdgeProxyJob $job) => $job->application->is($application));

    Queue::fake();

    $application->settings->update(['exclude_from_master_domain_routing' => true]);

    Queue::assertPushed(SyncApplicationEdgeProxyJob::class, fn (SyncApplicationEdgeProxyJob $job) => $job->application->is($application));
});

it('does not let a delayed deployment sync route to a server that is no longer assigned', function () {
    $team = Team::factory()->create();
    $currentServer = Server::factory()->create(['team_id' => $team->id]);
    $staleServer = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $currentServer->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    Queue::fake();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://current-server.example.com',
    ]);

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncApplication')->once()->with($application)->andReturn([]);
    $routeService->shouldNotReceive('syncApplicationOnDeploymentServer');

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('syncApplication')->once()->with($application)->andReturn([]);
    $portForwardService->shouldNotReceive('syncApplicationOnDeploymentServer');

    (new SyncApplicationEdgeProxyJob($application, $staleServer))->handle($routeService, $portForwardService);
});
