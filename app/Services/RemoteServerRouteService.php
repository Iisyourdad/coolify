<?php

namespace App\Services;

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class RemoteServerRouteService
{
    public function __construct(private RemoteRouteConfigurationBuilder $configurationBuilder) {}

    public function syncApplication(Application $application): void
    {
        $application->loadMissing('environment.project', 'destination.server', 'settings');
        $this->sync(
            teamId: data_get($application, 'environment.project.team_id'),
            resourceType: 'application',
            resourceUuid: $application->uuid,
            deploymentServer: data_get($application, 'destination.server'),
            domains: $this->applicationDomains($application),
        );
    }

    public function syncService(Service $service): void
    {
        $service->loadMissing('environment.project', 'server', 'applications');
        $this->sync(
            teamId: data_get($service, 'environment.project.team_id'),
            resourceType: 'service',
            resourceUuid: $service->uuid,
            deploymentServer: $service->server,
            domains: $this->serviceDomains($service),
        );
    }

    public function cleanup(int $teamId, string $resourceType, string $resourceUuid): void
    {
        Server::query()
            ->where('team_id', $teamId)
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->each(fn (Server $server) => $this->deleteRouteFile($server, $resourceType, $resourceUuid));
    }

    private function sync(?int $teamId, string $resourceType, string $resourceUuid, mixed $deploymentServer, array $domains): void
    {
        if ($teamId === null || ! $deploymentServer instanceof Server) {
            return;
        }

        $master = Server::query()
            ->where('team_id', $teamId)
            ->whereRelation('settings', 'is_master_domain_router_enabled', true)
            ->first();

        if (! $master instanceof Server || $master->id === $deploymentServer->id || $domains === []) {
            $this->cleanup($teamId, $resourceType, $resourceUuid);

            return;
        }

        $host = $this->remoteHost($deploymentServer);
        if ($host === null) {
            throw new \RuntimeException("Remote {$resourceType} route cannot be synchronized because the deployment server has no reachable host.");
        }

        $configuration = $this->configurationBuilder->build($resourceType, $resourceUuid, $host, $domains);
        if ($configuration['http']['routers'] === []) {
            $this->cleanup($teamId, $resourceType, $resourceUuid);

            return;
        }

        $this->writeRouteFile($master, $resourceType, $resourceUuid, $configuration);
        Server::query()
            ->where('team_id', $teamId)
            ->where('id', '!=', $master->id)
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->each(fn (Server $server) => $this->deleteRouteFile($server, $resourceType, $resourceUuid));
    }

    /** @return array<int, array{domain: string, noindex: bool, redirect: ?string, force_https: bool}> */
    private function applicationDomains(Application $application): array
    {
        if ($application->build_pack === 'dockercompose') {
            $configured = json_decode((string) $application->docker_compose_domains, true);
            if (is_array($configured)) {
                return collect($configured)
                    ->map(fn (mixed $item) => is_array($item) ? $item : ['domain' => $item])
                    ->flatMap(fn (array $item) => collect(explode(',', (string) data_get($item, 'domain')))
                        ->map(fn (string $domain) => $this->domain($domain, $application->isDomainNoindexed($domain), data_get($item, 'redirect', $application->redirect), $application->settings?->is_force_https_enabled ?? true)))
                    ->filter()
                    ->values()->all();
            }
        }

        return collect(explode(',', (string) $application->fqdn))
            ->map(fn (string $domain) => $this->domain($domain, $application->isDomainNoindexed($domain), $application->redirect, $application->settings?->is_force_https_enabled ?? true))
            ->filter()->values()->all();
    }

    /** @return array<int, array{domain: string, noindex: bool, redirect: ?string, force_https: bool}> */
    private function serviceDomains(Service $service): array
    {
        return $service->applications
            ->flatMap(function (ServiceApplication $application) {
                return collect(explode(',', (string) $application->fqdn))
                    ->map(fn (string $domain) => $this->domain($domain, $application->isDomainNoindexed($domain), $application->redirect, $application->is_force_https_enabled));
            })
            ->filter()->values()->all();
    }

    /** @return array{domain: string, noindex: bool, redirect: ?string, force_https: bool}|null */
    private function domain(string $domain, bool $noindex, ?string $redirect, bool $forceHttps): ?array
    {
        $domain = trim($domain);
        if ($domain === '' || ! isValidDomainUrl($domain)) {
            return null;
        }

        return ['domain' => $domain, 'noindex' => $noindex, 'redirect' => $redirect, 'force_https' => $forceHttps];
    }

    private function remoteHost(Server $server): ?string
    {
        foreach (['wireguard_ip', 'wg_ip', 'tunnel_ip', 'tunnel_host', 'tunnel_domain'] as $key) {
            $candidate = trim((string) data_get($server, "proxy.{$key}"));
            if ($candidate !== '') {
                return $this->normalizeHost($candidate);
            }
        }

        return $this->normalizeHost((string) $server->ip);
    }

    private function normalizeHost(string $host): ?string
    {
        $host = trim($host);
        if ($host === '') {
            return null;
        }
        $parsed = parse_url(str_contains($host, '://') ? $host : 'http://'.$host, PHP_URL_HOST);
        $host = is_string($parsed) ? trim($parsed, '[]') : '';

        return $host === '' ? null : (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host);
    }

    private function writeRouteFile(Server $server, string $resourceType, string $resourceUuid, array $configuration): void
    {
        $path = $this->routeFilePath($server, $resourceType, $resourceUuid);
        $temporary = $path.'.tmp-'.Str::random(12);
        $payload = base64_encode("# Generated by Coolify remote routing.\n\n".Yaml::dump($configuration, 12, 2));
        $this->runRemoteCommands($server, [
            'mkdir -p '.escapeshellarg(dirname($path)),
            "set -e; echo '{$payload}' | base64 -d > ".escapeshellarg($temporary).'; mv -f '.escapeshellarg($temporary).' '.escapeshellarg($path),
        ]);
    }

    private function deleteRouteFile(Server $server, string $resourceType, string $resourceUuid): void
    {
        $path = $this->routeFilePath($server, $resourceType, $resourceUuid);
        $this->runRemoteCommands($server, ['rm -f '.escapeshellarg($path).' '.escapeshellarg($path).'.tmp-*']);
    }

    protected function runRemoteCommands(Server $server, array $commands): ?string
    {
        return instant_remote_process($commands, $server);
    }

    private function routeFilePath(Server $server, string $resourceType, string $resourceUuid): string
    {
        return rtrim($server->proxyPath(), '/').'/dynamic/remote-'.$resourceType.'-'.$resourceUuid.'.yaml';
    }
}
