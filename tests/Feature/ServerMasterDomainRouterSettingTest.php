<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function masterRouterServer(array $attributes = []): Server
{
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();

    return Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => 'TRAEFIK'],
        ...$attributes,
    ]);
}

it('atomically replaces the team master domain router', function () {
    $first = masterRouterServer();
    $second = Server::factory()->create([
        'team_id' => $first->team_id,
        'proxy' => ['type' => 'TRAEFIK'],
    ]);

    ServerSetting::enableMasterDomainRouter($first);
    ServerSetting::enableMasterDomainRouter($second);

    expect($first->settings->fresh()->is_master_domain_router_enabled)->toBeFalse()
        ->and($second->settings->fresh()->is_master_domain_router_enabled)->toBeTrue();
});

it('rejects non-traefik and build servers as master domain routers', function () {
    $nonTraefik = masterRouterServer(['proxy' => ['type' => 'CADDY']]);
    $buildServer = masterRouterServer(['proxy' => ['type' => 'TRAEFIK']]);
    $buildServer->settings->update(['is_build_server' => true]);

    expect(fn () => ServerSetting::enableMasterDomainRouter($nonTraefik))
        ->toThrow(RuntimeException::class, 'Traefik')
        ->and(fn () => ServerSetting::enableMasterDomainRouter($buildServer))
        ->toThrow(RuntimeException::class, 'dedicated build server');
});

it('does not run master-router enforcement for unrelated setting updates', function () {
    $server = masterRouterServer();

    $server->settings->update(['connection_timeout' => 45]);

    expect($server->settings->fresh()->connection_timeout)->toBe(45)
        ->and($server->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();
});

it('queues reconciliation when the master domain router changes', function () {
    Queue::fake();
    $server = masterRouterServer();

    ServerSetting::enableMasterDomainRouter($server);
    ServerSetting::disableMasterDomainRouter($server);

    Queue::assertPushed(\App\Jobs\ReconcileTeamRemoteServerRoutesJob::class, 2);
});
