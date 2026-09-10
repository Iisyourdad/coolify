<?php

namespace App\Services;

use App\Support\DomainUrlParts;
use Illuminate\Support\Str;

class RemoteRouteConfigurationBuilder
{
    /**
     * @param  array<int, array{domain: string, noindex: bool, redirect: ?string, force_https: bool}>  $domains
     */
    public function build(string $resourceType, string $resourceUuid, string $upstreamHost, array $domains): array
    {
        $key = Str::slug($resourceType.'-'.$resourceUuid) ?: 'remote-route';
        $config = ['http' => ['routers' => [], 'services' => []]];

        foreach ($domains as $index => $domain) {
            $parts = DomainUrlParts::split($domain['domain']);
            if ($parts['host'] === '' || ! $this->isSafeRuleValue($parts['host']) || ! $this->isSafeRuleValue($parts['path'])) {
                continue;
            }

            $suffix = $index + 1;
            $name = "remote-{$key}-{$suffix}";
            $service = "{$name}-service";
            $rule = 'Host(`'.$parts['host'].'`)';
            if ($parts['path'] !== '' && $parts['path'] !== '/') {
                $rule .= ' && PathPrefix(`'.strtok($parts['path'], '?#').'`)';
            }

            $httpMiddlewares = [];
            $httpsMiddlewares = [];
            if ($domain['noindex']) {
                $noindex = "{$name}-noindex";
                $config['http']['middlewares'][$noindex] = [
                    'headers' => ['customResponseHeaders' => ['X-Robots-Tag' => 'noindex, nofollow']],
                ];
                $httpMiddlewares[] = $noindex;
                $httpsMiddlewares[] = $noindex;
            }

            $canonical = $this->canonicalRedirect($name, $parts['host'], $domain['redirect']);
            if ($canonical !== null) {
                $config['http']['middlewares'][$canonical['name']] = $canonical['configuration'];
                if ($parts['scheme'] === 'https') {
                    $httpsMiddlewares[] = $canonical['name'];
                } else {
                    $httpMiddlewares[] = $canonical['name'];
                }
            }

            $httpRouter = ['rule' => $rule, 'entryPoints' => ['http'], 'service' => $service];
            if ($parts['scheme'] === 'https') {
                if ($domain['force_https']) {
                    $redirect = "remote-{$key}-redirect-to-https";
                    $config['http']['middlewares'][$redirect] = ['redirectScheme' => ['scheme' => 'https']];
                    $httpMiddlewares[] = $redirect;
                }

                $tls = filter_var($parts['host'], FILTER_VALIDATE_IP) === false
                    ? ['certResolver' => 'letsencrypt']
                    : [];
                $httpsRouter = ['rule' => $rule, 'entryPoints' => ['https'], 'service' => $service, 'tls' => $tls];
                if ($httpsMiddlewares !== []) {
                    $httpsRouter['middlewares'] = $httpsMiddlewares;
                }
                $config['http']['routers']["{$name}-https"] = $httpsRouter;
            }
            if ($httpMiddlewares !== []) {
                $httpRouter['middlewares'] = $httpMiddlewares;
            }
            $config['http']['routers']["{$name}-http"] = $httpRouter;
            $config['http']['services'][$service] = [
                'loadBalancer' => [
                    'passHostHeader' => true,
                    'serversTransport' => "{$name}-transport",
                    'servers' => [['url' => $parts['scheme'].'://'.$upstreamHost.($parts['scheme'] === 'https' ? ':443' : ':80')]],
                ],
            ];
            $config['http']['serversTransports']["{$name}-transport"] = ['insecureSkipVerify' => true];
        }

        return $config;
    }

    private function isSafeRuleValue(string $value): bool
    {
        return ! Str::contains($value, ['`', "\n", "\r"]);
    }

    /** @return array{name: string, configuration: array}|null */
    private function canonicalRedirect(string $name, string $host, ?string $direction): ?array
    {
        $isWww = str_starts_with(strtolower($host), 'www.');
        if ($direction === 'www' && ! $isWww) {
            return [
                'name' => "{$name}-to-www",
                'configuration' => ['redirectRegex' => ['regex' => '^(http|https)://(?:www\\.)?(.+)', 'replacement' => '${1}://www.${2}', 'permanent' => false]],
            ];
        }
        if ($direction === 'non-www' && $isWww) {
            return [
                'name' => "{$name}-to-non-www",
                'configuration' => ['redirectRegex' => ['regex' => '^(http|https)://www\\.(.+)', 'replacement' => '${1}://${2}', 'permanent' => false]],
            ];
        }

        return null;
    }
}
