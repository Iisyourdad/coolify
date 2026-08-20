<?php

use App\Actions\Service\DeleteService;
use App\Exceptions\EdgeProxyCleanupPendingException;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;

it('keeps application pending deletion when edge route cleanup fails', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-cleanup-failure';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([
        'Failed to delete edge proxy route file for application application-cleanup-failure on edge server edge-1 (101): application route cleanup failed',
    ]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function deleteLocalResource(): void
        {
            $this->resource->forceDelete();
        }

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for application application-cleanup-failure');
});

it('keeps application pending deletion when edge port cleanup fails', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-port-cleanup-failure';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([
        'Failed to delete edge port proxy for application application-port-cleanup-failure on edge server edge-2 (202): application port cleanup failed',
    ]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for application application-port-cleanup-failure');
});

it('force deletes application after edge cleanup succeeds', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-cleanup-success';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->once();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function deleteLocalResource(): void
        {
            $this->resource->forceDelete();
        }

        protected function queueStuckedResourcesCleanup(): void {}
    };

    $job->handle();
});

it('keeps application pending deletion when concrete edge port cleanup hits an ssh error', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-concrete-port-ssh-failure';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->never();
    $application->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 402],
    ]);

    $routeService = new class extends EdgeProxyRemoteRouteService
    {
        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect();
        }
    };

    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 402;
    $edgeProxyServer->name = 'edge-port-timeout';

    $portForwardService = new class($edgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public function __construct(private Server $edgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect([$this->edgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            throw new RuntimeException('ssh: connect to host 10.10.10.11 port 22: Connection timed out');
        }
    };

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for application application-concrete-port-ssh-failure');
});

it('releases queued application deletion when edge cleanup is still pending', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-release-cleanup';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([
        'Failed to delete edge proxy route file for application application-release-cleanup on edge server edge-1 (101): application route cleanup failed',
    ]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        public ?int $releasedDelay = null;

        public int $cleanupQueueCount = 0;

        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void
        {
            $this->cleanupQueueCount++;
        }

        protected function shouldReleasePendingEdgeCleanup(): bool
        {
            return true;
        }

        public function backoff(): array
        {
            return [3, 7, 11];
        }

        public function attempts(): int
        {
            return 2;
        }

        public function release($delay = 0): void
        {
            $this->releasedDelay = $delay;
        }
    };

    $job->handle();

    expect($job->releasedDelay)->toBe(7)
        ->and($job->cleanupQueueCount)->toBe(1);
});

it('releases queued service deletion when edge cleanup is still pending', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-release-cleanup';
    $service->shouldReceive('trashed')->once()->andReturn(false);
    $service->shouldReceive('delete')->once();
    $service->shouldReceive('forceDelete')->never();

    $job = new class($service, false, false, false, false) extends DeleteResourceJob
    {
        public ?int $releasedDelay = null;

        public int $cleanupQueueCount = 0;

        protected function stopAndDeleteServiceResource(): void
        {
            throw new EdgeProxyCleanupPendingException('service', $this->resource->uuid, [
                'Failed to delete edge proxy route file for service service-release-cleanup on edge server edge-3 (303): route cleanup failed',
            ]);
        }

        protected function queueStuckedResourcesCleanup(): void
        {
            $this->cleanupQueueCount++;
        }

        protected function shouldReleasePendingEdgeCleanup(): bool
        {
            return true;
        }

        public function backoff(): array
        {
            return [4, 8, 12];
        }

        public function attempts(): int
        {
            return 1;
        }

        public function release($delay = 0): void
        {
            $this->releasedDelay = $delay;
        }
    };

    $job->handle();

    expect($job->releasedDelay)->toBe(4)
        ->and($job->cleanupQueueCount)->toBe(1);
});

it('uses the shared application edge lock to prevent deletion and sync races', function () {
    $application = new Application;
    $application->uuid = 'application-delete-lock';

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        public function lockKey(): string
        {
            return $this->deletionLockKey();
        }
    };

    $middlewares = $job->middleware();

    expect($middlewares)->toHaveCount(1)
        ->and($middlewares[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middlewares[0]->key)->toBe('application-edge-proxy-application-delete-lock')
        ->and($middlewares[0]->shareKey)->toBeTrue()
        ->and($middlewares[0]->releaseAfter)->toBe(30)
        ->and($job->tries)->toBe(0);
});

it('still gates service deletion on edge cleanup when stopping the service throws', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-stop-failure';

    $deleteService = Mockery::mock(DeleteService::class);
    $deleteService->shouldReceive('cleanupRemote')
        ->once()
        ->with($service, false, false, false)
        ->andThrow(new EdgeProxyCleanupPendingException('service', $service->uuid, [
            'edge route cleanup failed',
        ]));
    app()->instance(DeleteService::class, $deleteService);

    $job = new class($service, false, false, false, false) extends DeleteResourceJob
    {
        protected function stopServiceResource(): void
        {
            throw new RuntimeException('service stop failed');
        }

        public function runRemoteServiceCleanup(): void
        {
            $this->stopAndDeleteServiceResource();
        }
    };

    expect(fn () => $job->runRemoteServiceCleanup())
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for service service-stop-failure');
});

it('continues local service deletion after a stop failure when edge cleanup succeeds', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-ordinary-stop-failure';
    $service->shouldReceive('trashed')->once()->andReturn(false);
    $service->shouldReceive('delete')->once();

    $deleteService = Mockery::mock(DeleteService::class);
    $deleteService->shouldReceive('cleanupRemote')
        ->once()
        ->with($service, false, false, false);
    app()->instance(DeleteService::class, $deleteService);

    $job = new class($service, false, false, false, false) extends DeleteResourceJob
    {
        public bool $deletedLocally = false;

        public bool $loggedRemoteFailure = false;

        protected function stopServiceResource(): void
        {
            throw new RuntimeException('service stop failed');
        }

        protected function deleteLocalResource(): void
        {
            $this->deletedLocally = true;
        }

        protected function logRemoteCleanupFailure(Throwable $exception): void
        {
            $this->loggedRemoteFailure = $exception->getMessage() === 'service stop failed';
        }

        protected function queueStuckedResourcesCleanup(): void {}
    };

    $job->handle();

    expect($job->deletedLocally)->toBeTrue()
        ->and($job->loggedRemoteFailure)->toBeTrue();
});
