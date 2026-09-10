<?php

namespace App\Services;

use Illuminate\Support\Collection;

class RemotePortForwardConfigurationBuilder
{
    /** @param Collection<int, array{published:int, protocol:string}> $mappings */
    public function nginx(string $host, Collection $mappings): string
    {
        $servers = $mappings->map(function (array $mapping) use ($host): string {
            $udp = $mapping['protocol'] === 'udp' ? ' udp reuseport' : '';

            return "    server {\n        listen {$mapping['published']}{$udp};\n        proxy_pass {$host}:{$mapping['published']};\n    }";
        })->implode("\n");

        return "worker_processes auto;\nevents { worker_connections 1024; }\nstream {\n{$servers}\n}\n";
    }

    /** @param Collection<int, array{published:int, protocol:string}> $mappings */
    public function compose(string $container, string $directory, int $teamId, string $type, string $uuid, Collection $mappings): array
    {
        return ['services' => [$container => [
            'image' => 'nginx:stable-alpine',
            'container_name' => $container,
            'restart' => RESTART_MODE,
            'ports' => $mappings->map(fn (array $m) => "{$m['published']}:{$m['published']}".($m['protocol'] === 'udp' ? '/udp' : ''))->values()->all(),
            'volumes' => [[
                'type' => 'bind', 'source' => $directory.'/nginx.conf', 'target' => '/etc/nginx/nginx.conf', 'read_only' => true,
            ]],
            'labels' => [
                'coolify.managed=true',
                'coolify.remote-forward=true',
                "coolify.remote-forward.team-id={$teamId}",
                "coolify.remote-forward.resource-type={$type}",
                "coolify.remote-forward.resource-uuid={$uuid}",
            ],
        ]]];
    }
}
