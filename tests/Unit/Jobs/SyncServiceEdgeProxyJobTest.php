<?php

use App\Jobs\SyncServiceEdgeProxyJob;
use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;

$originalLogger = null;

beforeEach(function () use (&$originalLogger) {
    $container = Container::getInstance();
    $originalLogger = $container->bound('log') ? $container->make('log') : null;
    $container->instance('log', new NullLogger);
});

afterEach(function () use (&$originalLogger) {
    Mockery::close();

    if (is_null($originalLogger)) {
        return;
    }

    Container::getInstance()->instance('log', $originalLogger);
});

it('syncs service routes and port forwarding through the edge proxy job', function () {
    $service = new Service;
    $service->uuid = 'service-edge-sync';

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncService')
        ->once()
        ->with($service)
        ->andReturn(['route warning']);
    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('syncService')
        ->once()
        ->with($service)
        ->andReturn(['port warning']);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    (new SyncServiceEdgeProxyJob($service))->handle();

    expect(true)->toBeTrue();
});
