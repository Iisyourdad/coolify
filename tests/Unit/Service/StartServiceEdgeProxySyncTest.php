<?php

use Tests\TestCase;

uses(TestCase::class);

it('queues service edge proxy sync after the service start activity is created', function () {
    $startServiceFile = file_get_contents(app_path('Actions/Service/StartService.php'));

    $remoteProcessPosition = strpos($startServiceFile, 'remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: \'ServiceStatusChanged\');');
    $dispatchPosition = strpos($startServiceFile, 'SyncServiceEdgeProxyJob::dispatch($service);');

    expect($startServiceFile)
        ->toContain('SyncServiceEdgeProxyJob::dispatch($service);')
        ->not->toContain('EdgeProxyRemoteRouteService')
        ->not->toContain('EdgeProxyRemotePortForwardService');

    expect($remoteProcessPosition)->not->toBeFalse();
    expect($dispatchPosition)->not->toBeFalse();
    expect($remoteProcessPosition)->toBeLessThan($dispatchPosition);
});
