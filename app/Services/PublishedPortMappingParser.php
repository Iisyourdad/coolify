<?php

namespace App\Services;

use Illuminate\Support\Collection;

class PublishedPortMappingParser
{
    /** @param array<int, mixed> $ports @param array<string, mixed> $environment */
    public function parse(array $ports, array $environment = []): Collection
    {
        return collect($ports)->flatMap(function (mixed $definition) use ($environment): array {
            if (is_array($definition)) {
                $published = $this->port(data_get($definition, 'published'), $environment);
                $protocol = strtolower((string) data_get($definition, 'protocol', 'tcp'));
                if ($published === null || ! in_array($protocol, ['tcp', 'udp'], true) || $this->isLoopback((string) data_get($definition, 'host_ip', ''))) {
                    return [];
                }

                return [['published' => $published, 'protocol' => $protocol]];
            }

            return ($mapping = $this->stringMapping((string) $definition, $environment)) === null ? [] : [$mapping];
        })->unique(fn (array $mapping) => "{$mapping['protocol']}:{$mapping['published']}")->values();
    }

    private function stringMapping(string $definition, array $environment): ?array
    {
        $definition = trim($definition);
        if ($definition === '' || ! str_contains($definition, ':')) {
            return null;
        }
        $protocol = 'tcp';
        if (preg_match('/\/(tcp|udp)$/i', $definition, $match)) {
            $protocol = strtolower($match[1]);
            $definition = preg_replace('/\/(tcp|udp)$/i', '', $definition) ?? $definition;
        }
        if (! in_array($protocol, ['tcp', 'udp'], true)) {
            return null;
        }
        $bind = null;
        if (preg_match('/^\[([^]]+)]:(.+)$/', $definition, $match)) {
            $bind = $match[1];
            $definition = $match[2];
        }
        $parts = explode(':', $definition);
        if (count($parts) < 2) {
            return null;
        }
        array_pop($parts); // Container target port; it is not reachable from another server.
        $published = array_pop($parts);
        if ($parts !== []) {
            $bind ??= implode(':', $parts);
        }
        if ($this->isLoopback((string) $bind)) {
            return null;
        }
        $port = $this->port($published, $environment);

        return $port === null ? null : ['published' => $port, 'protocol' => $protocol];
    }

    private function port(mixed $value, array $environment): ?int
    {
        $value = trim((string) $value);
        if (preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-(\d+))?}$/', $value, $match)) {
            $value = trim((string) ($environment[$match[1]] ?? ($match[2] ?? '')));
        } elseif (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $value, $match)) {
            $value = trim((string) ($environment[$match[1]] ?? ''));
        }
        if (! ctype_digit($value)) {
            return null;
        }
        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    private function isLoopback(string $host): bool
    {
        $host = trim($host, ' []');

        return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    }
}
