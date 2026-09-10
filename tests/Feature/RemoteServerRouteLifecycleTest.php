<?php

use App\Jobs\SyncRemoteServerRouteJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->team = $user->teams()->firstOrFail();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
});

it('queues application route synchronization when domains change', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => \App\Models\StandaloneDocker::class,
        'fqdn' => 'https://old.example.com',
    ]);

    $application->update(['fqdn' => 'https://new.example.com']);

    Queue::assertPushed(SyncRemoteServerRouteJob::class, fn ($job) => $job->resource->is($application));
});

it('queues service route synchronization when a service domain changes', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->server->standaloneDockers()->firstOrFail()->id,
    ]);
    $application = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'fqdn' => 'https://old-service.example.com',
    ]);

    $application->update(['fqdn' => 'https://new-service.example.com']);

    Queue::assertPushed(SyncRemoteServerRouteJob::class, fn ($job) => $job->resource->is($service));
});
