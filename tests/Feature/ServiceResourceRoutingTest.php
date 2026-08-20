<?php

use App\Livewire\Project\Database\ImportForm as DatabaseImportForm;
use App\Livewire\Project\Service\Heading;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('cache.default', 'array');
    Config::set('app.maintenance.store', 'array');
    Config::set('queue.default', 'sync');

    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::factory()->create([
        'server_id' => $this->serverA->id,
        'network' => 'team-a-network',
    ]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);

    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::factory()->create([
        'server_id' => $this->serverB->id,
        'network' => 'team-b-network',
    ]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->otherService = Service::factory()->create([
        'server_id' => $this->serverB->id,
        'destination_id' => $this->destinationB->id,
        'destination_type' => $this->destinationB->getMorphClass(),
        'environment_id' => $this->environmentB->id,
    ]);
    $this->otherServiceApplication = ServiceApplication::create([
        'service_id' => $this->otherService->id,
        'name' => 'other-app',
        'image' => 'nginx:alpine',
    ]);
    $this->otherServiceDatabase = ServiceDatabase::create([
        'service_id' => $this->otherService->id,
        'name' => 'other-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    $this->ownService = Service::factory()->create([
        'server_id' => $this->serverA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
        'environment_id' => $this->environmentA->id,
    ]);
    $this->ownServiceDatabase = ServiceDatabase::create([
        'service_id' => $this->ownService->id,
        'name' => 'own-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('does not open service application detail route from another team', function () {
    $this->withoutExceptionHandling();

    $this->get(route('project.service.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->otherService->uuid,
        'stack_service_uuid' => $this->otherServiceApplication->uuid,
    ]));
})->throws(NotFoundHttpException::class);

test('does not open service database backups route from another team', function () {
    $this->withoutExceptionHandling();

    $this->get(route('project.service.database.backups', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->otherService->uuid,
        'stack_service_uuid' => $this->otherServiceDatabase->uuid,
    ]));
})->throws(NotFoundHttpException::class);

test('does not resolve service database import component from another team', function () {
    $component = app(DatabaseImportForm::class);
    $component->parameters = [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->otherService->uuid,
        'stack_service_uuid' => $this->otherServiceDatabase->uuid,
    ];

    $component->getContainers();
})->throws(ModelNotFoundException::class);

test('service heading does not hydrate with another team service', function () {
    Livewire::test(Heading::class, ['service' => $this->otherService]);
})->throws(ModelNotFoundException::class);

test('owner can still hydrate service heading with own service', function () {
    Livewire::test(Heading::class, [
        'service' => $this->ownService,
        'parameters' => [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
            'service_uuid' => $this->ownService->uuid,
        ],
    ])
        ->assertOk();
});

test('service database backup schedules use dedicated general retention and executions urls', function () {
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->teamA->id,
        'frequency' => 'daily',
        'database_id' => $this->ownServiceDatabase->id,
        'database_type' => $this->ownServiceDatabase->getMorphClass(),
        'save_s3' => true,
    ]);
    $listUrl = route('project.service.database.backups', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
        'stack_service_uuid' => $this->ownServiceDatabase->uuid,
    ]);
    $generalUrl = $listUrl.'/'.$backup->uuid;

    $this->get($listUrl)
        ->assertOk()
        ->assertSee('href="'.$generalUrl.'"', false);

    $this->get($generalUrl)
        ->assertOk()
        ->assertSee('Backup schedule')
        ->assertSee('Frequency')
        ->assertDontSee('Maximum storage (GB)')
        ->assertDontSee('Clean failed backups')
        ->assertDontSee('Delete schedule');

    $this->get($generalUrl.'/s3')
        ->assertOk()
        ->assertSee('S3 storage')
        ->assertSee('No validated S3 storage')
        ->assertDontSee('Frequency')
        ->assertDontSee('Maximum storage (GB)')
        ->assertDontSee('Clean failed backups');

    $this->get($generalUrl.'/retention')
        ->assertOk()
        ->assertSee('Retention')
        ->assertSee('Local backups')
        ->assertSee('S3 backups')
        ->assertSee('Backups to keep')
        ->assertSee('Days to keep')
        ->assertSee('Maximum storage (GB)')
        ->assertDontSee('Frequency')
        ->assertDontSee('Clean failed backups');

    $this->get($generalUrl.'/executions')
        ->assertOk()
        ->assertSee('<h2>Executions</h2>', false)
        ->assertDontSee('Executions <span', false)
        ->assertSee('Clean failed backups')
        ->assertDontSee('Frequency')
        ->assertDontSee('Backups to keep');

    $this->get($generalUrl.'/danger')
        ->assertOk()
        ->assertSee('Delete backup schedule')
        ->assertSee('Delete schedule')
        ->assertDontSee('Frequency')
        ->assertDontSee('Backups to keep')
        ->assertDontSee('Clean failed backups');
});

test('service storage backups page includes schedules from all compose databases', function () {
    $secondDatabase = ServiceDatabase::create([
        'service_id' => $this->ownService->id,
        'name' => 'analytics-db',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    foreach ([$this->ownServiceDatabase, $secondDatabase] as $database) {
        ScheduledDatabaseBackup::create([
            'team_id' => $this->teamA->id,
            'description' => $database->name.' backup',
            'frequency' => 'daily',
            'database_id' => $database->id,
            'database_type' => $database->getMorphClass(),
        ]);
    }

    $this->get(route('project.service.volume-backups.index', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->ownService->uuid,
    ]))
        ->assertOk()
        ->assertSee('>Database</span>', false)
        ->assertSee('own-db')
        ->assertSee('analytics-db')
        ->assertSee(route('project.service.database.backups', [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
            'service_uuid' => $this->ownService->uuid,
            'stack_service_uuid' => $this->ownServiceDatabase->uuid,
        ]), false);
});
