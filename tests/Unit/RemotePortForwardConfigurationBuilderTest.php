<?php

use App\Services\RemotePortForwardConfigurationBuilder;

it('builds isolated Minecraft TCP and UDP forwarding with ownership labels', function () {
    $builder = app(RemotePortForwardConfigurationBuilder::class);
    $mappings = collect([['published' => 25565, 'protocol' => 'tcp'], ['published' => 19132, 'protocol' => 'udp']]);
    $nginx = $builder->nginx('10.8.0.15', $mappings);
    $compose = $builder->compose('coolify-remote-forward-application-game', '/data/coolify/remote-forwarders/application-game', 1, 'application', 'game', $mappings);
    expect($nginx)->toContain('listen 25565;', 'proxy_pass 10.8.0.15:25565;', 'listen 19132 udp reuseport;', 'proxy_pass 10.8.0.15:19132;')
        ->and(data_get($compose, 'services.coolify-remote-forward-application-game.ports'))->toBe(['25565:25565', '19132:19132/udp'])
        ->and(data_get($compose, 'services.coolify-remote-forward-application-game.labels'))->toContain('coolify.remote-forward=true');
});
