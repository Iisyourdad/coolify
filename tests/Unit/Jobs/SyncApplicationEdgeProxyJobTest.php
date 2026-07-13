<?php

use App\Jobs\SyncApplicationEdgeProxyJob;
use App\Models\Application;
use App\Models\Server;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Container\Container;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Psr\Log\NullLogger;

$originalLogger = null;

beforeEach(function () use (&$originalLogger) {
    $container = Container::getInstance();
    $originalLogger = $container->bound('log') ? $container->make('log') : null;
    $container->instance('log', new NullLogger);
});

afterEach(function () use (&$originalLogger) {
    Mockery::close();

    if (! is_null($originalLogger)) {
        Container::getInstance()->instance('log', $originalLogger);
    }
});

it('syncs application routes and ports to the deployment server that completed', function () {
    $application = new Application;
    $application->uuid = 'application-edge-sync';

    $deploymentServer = new Server;
    $deploymentServer->id = 42;

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncApplicationOnDeploymentServer')
        ->once()
        ->with($application, $deploymentServer)
        ->andReturn([]);
    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('syncApplicationOnDeploymentServer')
        ->once()
        ->with($application, $deploymentServer)
        ->andReturn([]);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    (new SyncApplicationEdgeProxyJob($application, $deploymentServer))->handle($routeService, $portForwardService);
});

it('rethrows sync failures after attempting both route and port reconciliation', function () {
    $application = new Application;
    $application->uuid = 'application-edge-sync-failure';

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncApplication')
        ->once()
        ->with($application)
        ->andThrow(new RuntimeException('master router SSH failed'));
    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('syncApplication')
        ->once()
        ->with($application)
        ->andReturn([]);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    expect(fn () => (new SyncApplicationEdgeProxyJob($application))->handle($routeService, $portForwardService))
        ->toThrow(RuntimeException::class, 'master router SSH failed');
});

it('releases overlapping syncs and retries transient failures', function () {
    $application = new Application;
    $application->uuid = 'application-edge-sync-retry';

    $job = new SyncApplicationEdgeProxyJob($application);
    $middleware = $job->middleware();

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([5, 15, 30, 60])
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->releaseAfter)->toBe(5);
});
