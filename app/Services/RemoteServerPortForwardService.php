<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
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
        Server::query()->where('team_id', $teamId)->each(fn (Server $server) => $this->remove($server, $type, $uuid));
    }

    private function sync(?int $teamId, string $type, string $uuid, mixed $deploymentServer, Collection $mappings): void
    {
        if ($teamId === null || ! $deploymentServer instanceof Server) return;
        $master = $this->targets->masterForTeam($teamId);
        if (! $master instanceof Server || $master->id === $deploymentServer->id || $mappings->isEmpty()) {
            $this->cleanup($teamId, $type, $uuid); return;
        }
        $mappings = $this->withoutReserved($master, $mappings);
        $host = $this->targets->host($deploymentServer);
        if ($host === null || $mappings->isEmpty()) { $this->remove($master, $type, $uuid); return; }
        $this->assertNoCollision($master, $type, $uuid, $mappings);
        $this->write($master, $teamId, $type, $uuid, $host, $mappings);
        // Do not tear down a former master until the new master's forwarder was
        // successfully recreated.
        Server::query()->where('team_id', $teamId)->where('id', '!=', $master->id)
            ->each(fn (Server $server) => $this->remove($server, $type, $uuid));
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

    private function withoutReserved(Server $master, Collection $mappings): Collection
    {
        $reserved = ['tcp:80', 'tcp:443'];
        // v4's Traefik default publishes QUIC/HTTP3 on 443/udp.
        if ($master->proxyType() === 'TRAEFIK') $reserved[] = 'udp:443';
        return $mappings->reject(fn (array $m) => in_array("{$m['protocol']}:{$m['published']}", $reserved, true))->values();
    }

    private function assertNoCollision(Server $master, string $type, string $uuid, Collection $wanted): void
    {
        $output = $this->run($master, ["docker ps --filter ".escapeshellarg('label=coolify.remote-forward=true')." --format '{{.Names}} {{.Label \"coolify.remote-forward.resource-type\"}} {{.Label \"coolify.remote-forward.resource-uuid\"}} {{.Ports}}'"]);
        foreach (explode("\n", (string) $output) as $line) {
            if (! str_contains($line, '->')) continue;
            foreach ($wanted as $mapping) {
                if (str_contains($line, ":{$mapping['published']}->") && str_contains($line, "/{$mapping['protocol']}") && ! str_contains($line, " {$type} {$uuid} ")) {
                    throw new \RuntimeException("Remote forwarding conflict: {$mapping['protocol']}/{$mapping['published']} is owned by another managed resource on the master server.");
                }
            }
        }
    }

    private function write(Server $master, int $teamId, string $type, string $uuid, string $host, Collection $mappings): void
    {
        $container = "coolify-remote-forward-{$type}-{$uuid}";
        $directory = rtrim(base_configuration_dir(), '/')."/remote-forwarders/{$type}-{$uuid}";
        $nginx = base64_encode($this->configuration->nginx($host, $mappings));
        $compose = base64_encode(Yaml::dump($this->configuration->compose($container, $directory, $teamId, $type, $uuid, $mappings), 8, 2));
        $tmp = Str::random(12);
        $commands = [
            'mkdir -p '.escapeshellarg($directory),
            "echo '{$nginx}' | base64 -d > ".escapeshellarg("{$directory}/nginx.conf.tmp-{$tmp}"),
            "echo '{$compose}' | base64 -d > ".escapeshellarg("{$directory}/docker-compose.yaml.tmp-{$tmp}"),
            'mv -f '.escapeshellarg("{$directory}/nginx.conf.tmp-{$tmp}").' '.escapeshellarg("{$directory}/nginx.conf"),
            'mv -f '.escapeshellarg("{$directory}/docker-compose.yaml.tmp-{$tmp}").' '.escapeshellarg("{$directory}/docker-compose.yaml"),
            'docker compose --project-directory '.escapeshellarg($directory).' -f '.escapeshellarg("{$directory}/docker-compose.yaml").' config -q',
            'docker compose --project-directory '.escapeshellarg($directory).' -f '.escapeshellarg("{$directory}/docker-compose.yaml").' up -d --force-recreate',
            'if which ufw >/dev/null 2>&1 && which ufw-docker >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "^Status: active"; then ufw-docker delete allow '.escapeshellarg($container).' >/dev/null 2>&1 || true; ufw-docker allow '.escapeshellarg($container).'; fi',
        ];
        $this->run($master, $commands);
    }

    private function remove(Server $server, string $type, string $uuid): void
    {
        $container = "coolify-remote-forward-{$type}-{$uuid}";
        $directory = rtrim(base_configuration_dir(), '/')."/remote-forwarders/{$type}-{$uuid}";
        $this->run($server, ['if docker inspect '.escapeshellarg($container).' --format '.escapeshellarg('{{ index .Config.Labels "coolify.remote-forward" }}').' 2>/dev/null | grep -qx true; then docker rm -f '.escapeshellarg($container).'; fi', 'if which ufw-docker >/dev/null 2>&1; then ufw-docker delete allow '.escapeshellarg($container).' >/dev/null 2>&1 || true; fi', 'rm -rf '.escapeshellarg($directory)]);
    }

    private function run(Server $server, array $commands): ?string { return instant_remote_process($commands, $server); }
}
