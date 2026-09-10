<?php

use App\Services\RemoteRouteConfigurationBuilder;

it('does not generate a route for a local resource until synchronization selects a remote server', function () {
    $builder = app(RemoteRouteConfigurationBuilder::class);

    $configuration = $builder->build('application', 'local-app', '10.0.0.2', []);

    expect($configuration['http']['routers'])->toBeEmpty();
});

it('builds one secure master route per remote application domain', function () {
    $builder = app(RemoteRouteConfigurationBuilder::class);

    $configuration = $builder->build('application', 'app-uuid', '10.8.0.15', [
        ['domain' => 'https://app.example.com', 'noindex' => false, 'redirect' => null, 'force_https' => true],
        ['domain' => 'https://www.app.example.com/api', 'noindex' => true, 'redirect' => 'non-www', 'force_https' => true],
    ]);

    expect(data_get($configuration, 'http.routers.remote-application-app-uuid-1-https.rule'))->toBe('Host(`app.example.com`)')
        ->and(data_get($configuration, 'http.services.remote-application-app-uuid-1-service.loadBalancer.servers.0.url'))->toBe('https://10.8.0.15:443')
        ->and(data_get($configuration, 'http.routers.remote-application-app-uuid-2-https.rule'))->toBe('Host(`www.app.example.com`) && PathPrefix(`/api`)')
        ->and(data_get($configuration, 'http.middlewares.remote-application-app-uuid-2-noindex.headers.customResponseHeaders.X-Robots-Tag'))->toBe('noindex, nofollow');
});

it('keeps explicit http service domains on the http entrypoint', function () {
    $builder = app(RemoteRouteConfigurationBuilder::class);

    $configuration = $builder->build('service', 'service-uuid', '10.8.0.20', [
        ['domain' => 'http://service.example.com', 'noindex' => false, 'redirect' => null, 'force_https' => false],
    ]);

    expect(data_get($configuration, 'http.routers.remote-service-service-uuid-1-http.entryPoints'))->toBe(['http'])
        ->and(data_get($configuration, 'http.routers.remote-service-service-uuid-1-https'))->toBeNull()
        ->and(data_get($configuration, 'http.services.remote-service-service-uuid-1-service.loadBalancer.servers.0.url'))->toBe('http://10.8.0.20:80');
});
