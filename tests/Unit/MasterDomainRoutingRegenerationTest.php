<?php

use App\Livewire\Project\Application\Advanced as ApplicationAdvanced;
use App\Livewire\Project\Service\Index as ServiceIndex;
use App\Livewire\Project\Service\StackForm;
use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Services\EdgeProxyRemoteRouteService;

it('regenerates application master domain routing when exclusion is disabled', function () {
    $application = new Application;
    $application->uuid = 'application-regenerate-master-domain-routing';

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncApplication')->once()->with($application)->andReturn([]);
    $routeService->shouldReceive('deleteApplication')->never();
    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);

    $component = new ApplicationAdvanced;
    $component->application = $application;
    $component->excludeFromMasterDomainRouting = false;

    (function (): void {
        $this->syncMasterDomainRoutesIfExclusionChanged(true);
    })->call($component);
});

it('regenerates service master domain routing when exclusion is disabled', function () {
    $service = new Service;
    $service->uuid = 'service-regenerate-master-domain-routing';

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncService')->once()->with($service)->andReturn([]);
    $routeService->shouldReceive('deleteService')->never();
    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);

    $component = new StackForm;
    $component->service = $service;
    $component->excludeFromMasterDomainRouting = false;

    (function (): void {
        $this->syncMasterDomainRoutesIfExclusionChanged(true);
    })->call($component);
});

it('regenerates service routes when service application exclusion is disabled', function () {
    $service = new Service;
    $service->uuid = 'service-app-regenerate-master-domain-routing';

    $serviceApplication = new ServiceApplication;
    $serviceApplication->uuid = 'service-application-regenerate-master-domain-routing';
    $serviceApplication->setRelation('service', $service);

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('syncService')->once()->with($service)->andReturn([]);
    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);

    $component = new ServiceIndex;
    $component->serviceApplication = $serviceApplication;
    $component->excludeFromMasterDomainRouting = false;

    (function (): void {
        $this->syncServiceMasterDomainRoutesIfApplicationExclusionChanged(true);
    })->call($component);
});
