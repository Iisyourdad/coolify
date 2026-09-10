<?php

namespace App\Services;

use App\Exceptions\RemotePortForwardingConflictException;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class RemoteServerPortForwardService
{
    public function __construct(
        private RemoteServerTargetResolver $targets,
        private PublishedPortMappingParser $ports,
        private RemotePortForwardConfigurationBuilder $configuration,
    ) {}

    public function syncApplication(Application $application, ?Server $deploymentServer = null): void
    {
        $application->loadMissing('environment.project', 'destination.server');
        $this->sync(data_get($application, 'environment.project.team_id'), 'application', $application->uuid, $deploymentServer ?? data_get($application, 'destination.server'), $this->applicationPorts($application));
    }

    public function syncService(Service $service): void
    {
        $service->loadMissing('environment.project', 'server');
        $this->sync(data_get($service, 'environment.project.team_id'), 'service', $service->uuid, $service->server, $this->composePorts((string) $service->docker_compose, $this->environment($service)));
    }

    public function cleanup(int $teamId, string $type, string $uuid): void
    {
        $errors = [];
        Server::query()->where('team_id', $teamId)->each(function (Server $server) use ($type, $uuid, &$errors): void {
            try {
                $this->remove($server, $type, $uuid);
            } catch (\Throwable $exception) {
                $errors[] = "{$server->name}: {$exception->getMessage()}";
            }
        });
        if ($errors !== []) {
            throw new \RuntimeException('Remote forwarder cleanup remains pending on: '.implode('; ', $errors));
        }
    }

    private function sync(?int $teamId, string $type, string $uuid, mixed $deploymentServer, Collection $mappings): void
    {
        if ($teamId === null || ! $deploymentServer instanceof Server) return;
        $master = $this->targets->masterForTeam($teamId);
        if (! $master instanceof Server || $master->id === $deploymentServer->id || $mappings->isEmpty()) {
            $this->cleanupReachable($teamId, $type, $uuid); return;
        }
        [$mappings, $warnings] = $this->withoutReserved($master, $type, $uuid, $mappings);
        foreach ($warnings as $warning) {
            Log::warning($warning);
        }
        // Keep an existing healthy forwarder intact when a requested update only
        // contains infrastructure ports that cannot be claimed by this feature.
        if ($mappings->isEmpty() && $warnings !== []) return;
        $host = $this->targets->host($deploymentServer);
        if ($host === null || $mappings->isEmpty()) { $this->remove($master, $type, $uuid); return; }
        $this->assertNoCollision($master, $type, $uuid, $mappings);
        $this->write($master, $teamId, $type, $uuid, $host, $mappings);
        // Do not tear down a former master until the new master's forwarder was
        // successfully recreated.
        Server::query()->where('team_id', $teamId)->where('id', '!=', $master->id)
            ->whereRelation('settings', 'is_reachable', true)
            ->each(fn (Server $server) => $this->tryRemove($server, $type, $uuid));
    }

    private function cleanupReachable(int $teamId, string $type, string $uuid): void
    {
        Server::query()->where('team_id', $teamId)
            ->whereRelation('settings', 'is_reachable', true)
            ->each(fn (Server $server) => $this->tryRemove($server, $type, $uuid));
    }

    private function tryRemove(Server $server, string $type, string $uuid): void
    {
        try {
            $this->remove($server, $type, $uuid);
        } catch (\Throwable $exception) {
            Log::warning('Deferred remote forwarder cleanup after routine synchronization.', [
                'server_id' => $server->id, 'resource_type' => $type,
                'resource_uuid' => $uuid, 'error' => $exception->getMessage(),
            ]);
        }
    }

    private function applicationPorts(Application $application): Collection
    {
        if ($application->build_pack === 'dockercompose') return $this->composePorts((string) $application->docker_compose_raw, $this->environment($application));
        return $this->ports->parse($application->ports_mappings_array, $this->environment($application));
    }

    private function composePorts(string $compose, array $environment): Collection
    {
        try { $services = data_get(Yaml::parse($compose), 'services', []); } catch (\Throwable) { return collect(); }
        return collect($services)->flatMap(fn ($service) => is_array($service) ? $this->ports->parse((array) data_get($service, 'ports', []), $environment) : collect())
            ->unique(fn (array $m) => "{$m['protocol']}:{$m['published']}")->values();
    }

    private function environment(Application|Service $resource): array
    {
        return $resource->environment_variables()->get()->mapWithKeys(fn ($v) => [$v->key => $v->value])->all();
    }

    /** @return array{0: Collection, 1: list<string>} */
    protected function withoutReserved(Server $master, string $type, string $uuid, Collection $mappings): array
    {
        $reserved = ['tcp:80', 'tcp:443'];
        // v4's Traefik default publishes QUIC/HTTP3 on 443/udp.
        if ($master->proxyType() === 'TRAEFIK') $reserved[] = 'udp:443';
        $warnings = [];
        $allowed = $mappings->reject(function (array $mapping) use ($reserved, $type, $uuid, &$warnings): bool {
            $key = "{$mapping['protocol']}:{$mapping['published']}";
            if (! in_array($key, $reserved, true)) return false;
            $warnings[] = "Remote forwarding skipped {$key} for {$type} {$uuid}: this port is reserved by the master proxy infrastructure.";
            return true;
        })->values();

        return [$allowed, $warnings];
    }

    private function assertNoCollision(Server $master, string $type, string $uuid, Collection $wanted): void
    {
        $output = $this->run($master, ["docker ps --filter ".escapeshellarg('label=coolify.remote-forward=true')." --format '{{.Names}} {{.Label \"coolify.remote-forward.resource-type\"}} {{.Label \"coolify.remote-forward.resource-uuid\"}} {{.Ports}}'"]);
        foreach (explode("\n", (string) $output) as $line) {
            if (! str_contains($line, '->')) continue;
            foreach ($wanted as $mapping) {
                if (str_contains($line, ":{$mapping['published']}->") && str_contains($line, "/{$mapping['protocol']}") && ! str_contains($line, " {$type} {$uuid} ")) {
                    throw new RemotePortForwardingConflictException("Remote forwarding conflict: {$mapping['protocol']}/{$mapping['published']} is owned by another managed resource on the master server.");
                }
            }
        }
    }

    protected function write(Server $master, int $teamId, string $type, string $uuid, string $host, Collection $mappings): void
    {
        $container = "coolify-remote-forward-{$type}-{$uuid}";
        $directory = rtrim(base_configuration_dir(), '/')."/remote-forwarders/{$type}-{$uuid}";
        $nginx = base64_encode($this->configuration->nginx($host, $mappings));
        $compose = base64_encode(Yaml::dump($this->configuration->compose($container, $directory, $teamId, $type, $uuid, $mappings), 8, 2));
        $tmp = Str::random(12);
        $temporaryNginx = "{$directory}/nginx.conf.tmp-{$tmp}";
        $temporaryCompose = "{$directory}/docker-compose.yaml.tmp-{$tmp}";
        $previousNginx = "{$directory}/nginx.conf.previous-{$tmp}";
        $previousCompose = "{$directory}/docker-compose.yaml.previous-{$tmp}";
        $composeCommand = 'docker compose --project-directory '.escapeshellarg($directory).' -f '.escapeshellarg("{$directory}/docker-compose.yaml");
        $commands = [
            'set -e',
            'mkdir -p '.escapeshellarg($directory),
            "echo '{$nginx}' | base64 -d > ".escapeshellarg($temporaryNginx),
            "echo '{$compose}' | base64 -d > ".escapeshellarg($temporaryCompose),
            'docker compose --project-directory '.escapeshellarg($directory).' -f '.escapeshellarg($temporaryCompose).' config -q',
            'docker run --rm --network none -v '.escapeshellarg($temporaryNginx.':/etc/nginx/nginx.conf:ro').' nginx:stable-alpine nginx -t',
            // Back up the last known good files before the atomic replacement.
            '[ ! -f '.escapeshellarg("{$directory}/nginx.conf").' ] || cp -f '.escapeshellarg("{$directory}/nginx.conf").' '.escapeshellarg($previousNginx),
            '[ ! -f '.escapeshellarg("{$directory}/docker-compose.yaml").' ] || cp -f '.escapeshellarg("{$directory}/docker-compose.yaml").' '.escapeshellarg($previousCompose),
            'mv -f '.escapeshellarg($temporaryNginx).' '.escapeshellarg("{$directory}/nginx.conf"),
            'mv -f '.escapeshellarg($temporaryCompose).' '.escapeshellarg("{$directory}/docker-compose.yaml"),
            "if ! {$composeCommand} up -d --force-recreate; then ".
                '[ ! -f '.escapeshellarg($previousNginx).' ] || mv -f '.escapeshellarg($previousNginx).' '.escapeshellarg("{$directory}/nginx.conf").'; '.
                '[ ! -f '.escapeshellarg($previousCompose).' ] || mv -f '.escapeshellarg($previousCompose).' '.escapeshellarg("{$directory}/docker-compose.yaml").'; '.
                "{$composeCommand} up -d || true; exit 1; fi",
            'rm -f '.escapeshellarg($previousNginx).' '.escapeshellarg($previousCompose),
            'if which ufw >/dev/null 2>&1 && which ufw-docker >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "^Status: active"; then ufw-docker delete allow '.escapeshellarg($container).' >/dev/null 2>&1 || true; ufw-docker allow '.escapeshellarg($container).'; fi',
        ];
        try {
            $this->run($master, $commands);
        } catch (\Throwable $exception) {
            if (str($exception->getMessage())->lower()->contains(['port is already allocated', 'address already in use', 'failed programming external connectivity'])) {
                throw new RemotePortForwardingConflictException("Remote forwarding update failed for {$type} {$uuid}; an existing listener or host process owns one of the requested ports. The previous managed configuration was restored where available. {$exception->getMessage()}", previous: $exception);
            }
            throw $exception;
        }
    }

    private function remove(Server $server, string $type, string $uuid): void
    {
        $container = "coolify-remote-forward-{$type}-{$uuid}";
        $directory = rtrim(base_configuration_dir(), '/')."/remote-forwarders/{$type}-{$uuid}";
        $this->run($server, ['if docker inspect '.escapeshellarg($container).' --format '.escapeshellarg('{{ index .Config.Labels "coolify.remote-forward" }}').' 2>/dev/null | grep -qx true; then docker rm -f '.escapeshellarg($container).'; fi', 'if which ufw-docker >/dev/null 2>&1; then ufw-docker delete allow '.escapeshellarg($container).' >/dev/null 2>&1 || true; fi', 'rm -rf '.escapeshellarg($directory)]);
    }

    protected function run(Server $server, array $commands): ?string { return instant_remote_process($commands, $server); }
}
