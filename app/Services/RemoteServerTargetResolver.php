<?php

namespace App\Services;

use App\Enums\ProxyTypes;
use App\Models\Server;

class RemoteServerTargetResolver
{
    public function masterForTeam(?int $teamId): ?Server
    {
        if ($teamId === null) {
            return null;
        }

        return Server::query()
            ->where('team_id', $teamId)
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_master_domain_router_enabled', true)
            ->first();
    }

    /** Returns a host safe to interpolate into an upstream address. */
    public function host(Server $server): ?string
    {
        foreach (['wireguard_ip', 'wg_ip', 'tunnel_ip', 'tunnel_host', 'tunnel_domain'] as $key) {
            $host = $this->normalize((string) data_get($server, "proxy.{$key}"));
            if ($host !== null) {
                return $host;
            }
        }

        return $this->normalize((string) $server->ip);
    }

    private function normalize(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
            $candidate = trim($candidate, '[]');
        } elseif (str_contains($candidate, '://') || str_contains($candidate, '/')) {
            $parsed = parse_url(str_contains($candidate, '://') ? $candidate : "//{$candidate}", PHP_URL_HOST);
            $candidate = is_string($parsed) ? $parsed : '';
        }

        $candidate = trim($candidate, '[]');
        if ($candidate === '') {
            return null;
        }

        return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$candidate}]" : $candidate;
    }
}
