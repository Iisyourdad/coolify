<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('writes a new remote application route on the master domain router', function () {
    $team = Team::factory()->create();
    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'ip' => '10.8.0.10',
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $masterServer->settings->update(['is_master_domain_router_enabled' => true]);

    $deploymentServer = Server::factory()->create([
        'team_id' => $team->id,
        'ip' => '10.8.0.20',
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $deploymentServer->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    Queue::fake();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://remote-app.example.com',
        'ports_exposes' => '3000',
        'ports_mappings' => '18080:3000',
    ]);

    $routeService = new class extends EdgeProxyRemoteRouteService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
            ];

            return null;
        }
    };

    expect($routeService->syncApplication($application->fresh()))->toBe([]);

    $writeCall = collect($routeService->calls)->first(fn (array $call) => collect($call['commands'])->contains(
        fn (string $command) => str_contains($command, 'application-remote-'.$application->uuid.'.yaml.tmp')
    ));

    expect($writeCall)->not->toBeNull()
        ->and($writeCall['server_id'])->toBe($masterServer->id);

    $writeCommand = collect($writeCall['commands'])->first(
        fn (string $command) => str_contains($command, 'base64 -d')
    );
    preg_match("/echo '([^']+)' \| base64 -d/", $writeCommand, $payloadMatches);
    $routeConfiguration = base64_decode($payloadMatches[1] ?? '');

    expect($routeConfiguration)
        ->toContain('Host(`remote-app.example.com`)')
        ->toContain('http://10.8.0.20:18080');
});
