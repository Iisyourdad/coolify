<?php

use App\Models\Server;
use App\Services\RemoteServerTargetResolver;

it('uses only the target server configured tunnel address precedence', function () {
    $server = new Server(['ip' => '198.51.100.9', 'proxy' => [
        'wireguard_ip' => '10.8.0.10', 'wg_ip' => '10.9.0.10', 'tunnel_ip' => '10.10.0.10', 'tunnel_host' => 'tunnel.example.test', 'tunnel_domain' => 'fallback.example.test',
    ]]);
    expect(app(RemoteServerTargetResolver::class)->host($server))->toBe('10.8.0.10');
});

it('normalizes bare and bracketed IPv6 target addresses', function () {
    $resolver = app(RemoteServerTargetResolver::class);
    expect($resolver->host(new Server(['ip' => '2001:db8::5'])))->toBe('[2001:db8::5]')
        ->and($resolver->host(new Server(['ip' => '[2001:db8::6]'])))->toBe('[2001:db8::6]');
});
