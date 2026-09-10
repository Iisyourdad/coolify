<?php

use App\Models\Server;
use App\Models\User;
use App\Services\RemoteRouteConfigurationBuilder;
use App\Services\RemoteServerRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('only removes deterministic remote-route files during cleanup', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
        'proxy' => ['type' => 'TRAEFIK'],
    ]);
    $service = new class(app(RemoteRouteConfigurationBuilder::class)) extends RemoteServerRouteService
    {
        public array $commands = [];

        protected function runRemoteCommands(Server $server, array $commands): ?string
        {
            $this->commands = $commands;

            return null;
        }
    };

    $service->cleanup($server->team_id, 'application', 'application-uuid');

    expect($service->commands)->toHaveCount(1)
        ->and($service->commands[0])->toContain('remote-application-application-uuid.yaml')
        ->and($service->commands[0])->not->toContain('remote-service-');
});
