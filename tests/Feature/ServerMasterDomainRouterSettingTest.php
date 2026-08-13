<?php

use App\Enums\ProxyTypes;
use App\Livewire\Server\Show;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows only one master domain router server per team', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $firstServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $secondServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    $firstServer->settings->update(['is_master_domain_router_enabled' => true]);

    expect($firstServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue()
        ->and($secondServer->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();

    $secondServer->settings->update(['is_master_domain_router_enabled' => true]);

    expect($firstServer->settings->fresh()->is_master_domain_router_enabled)->toBeFalse()
        ->and($secondServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue();
});

it('does not disable master domain router setting on servers from other teams', function () {
    $teamOneUser = User::factory()->create();
    $teamTwoUser = User::factory()->create();

    $teamOneServer = Server::factory()->create([
        'team_id' => $teamOneUser->teams()->first()->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $teamTwoServer = Server::factory()->create([
        'team_id' => $teamTwoUser->teams()->first()->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    $teamOneServer->settings->update(['is_master_domain_router_enabled' => true]);
    $teamTwoServer->settings->update(['is_master_domain_router_enabled' => true]);

    expect($teamOneServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue()
        ->and($teamTwoServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue();
});

it('rejects enabling master domain routing on non-traefik servers', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::CADDY->value],
    ]);

    expect(fn () => $server->settings->update(['is_master_domain_router_enabled' => true]))
        ->toThrow(RuntimeException::class, "Master domain routing can only be enabled on Traefik servers. Server {$server->id} uses proxy type caddy.");

    expect($server->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();
});

it('rejects enabling master domain routing on dedicated build servers', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $server->settings->update(['is_build_server' => true]);

    expect(fn () => $server->settings->update(['is_master_domain_router_enabled' => true]))
        ->toThrow(RuntimeException::class, 'Master domain routing cannot be enabled on a dedicated build server.');

    expect($server->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();
});

it('rejects changing an enabled master domain router into a dedicated build server', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $server->settings->update(['is_master_domain_router_enabled' => true]);

    expect(fn () => $server->settings->update(['is_build_server' => true]))
        ->toThrow(RuntimeException::class, 'Master domain routing cannot be enabled on a dedicated build server.');

    expect($server->settings->fresh()->is_build_server)->toBeFalse()
        ->and($server->settings->fresh()->is_master_domain_router_enabled)->toBeTrue();
});

it('rejects changing an enabled master domain router away from traefik', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $server->settings->update(['is_master_domain_router_enabled' => true]);

    expect(fn () => $server->changeProxy(ProxyTypes::CADDY->value))
        ->toThrow(RuntimeException::class, 'A master domain router must keep using the Traefik proxy.');

    expect($server->fresh()->proxyType())->toBe(ProxyTypes::TRAEFIK->value);
});

it('locks master domain router toggle when another server in the same team is already selected', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $this->actingAs($user);
    refreshSession($team);

    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $otherServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    $masterServer->settings->update(['is_master_domain_router_enabled' => true]);

    Livewire::test(Show::class, ['server_uuid' => $otherServer->uuid])
        ->assertSet('isMasterDomainRouterLocked', true)
        ->assertSet('isMasterDomainRouterEnabled', false)
        ->set('isMasterDomainRouterEnabled', true)
        ->assertSet('isMasterDomainRouterEnabled', false);

    expect($otherServer->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();
});

it('locks master domain router toggle on dedicated build servers', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $this->actingAs($user);
    refreshSession($team);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $server->settings->update(['is_build_server' => true]);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->assertSet('isMasterDomainRouterLocked', true)
        ->set('isMasterDomainRouterEnabled', true)
        ->assertSet('isMasterDomainRouterEnabled', false);

    expect($server->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();
});
