<?php

describe('deployment job structure: edge proxy sync after deployment', function () {
    it('syncs edge proxy routing and port forwarding after non-preview deployments', function () {
        $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

        $methodStart = strpos($deploymentJobFile, 'private function post_deployment()');
        $methodEnd = strpos($deploymentJobFile, 'private function deploy_simple_dockerfile()');
        $methodBlock = substr($deploymentJobFile, $methodStart, $methodEnd - $methodStart);

        expect($methodBlock)
            ->toContain('if ($this->pull_request_id === 0)')
            ->toContain('EdgeProxyRemoteRouteService::class')
            ->toContain('EdgeProxyRemotePortForwardService::class')
            ->toContain('syncApplicationOnDeploymentServer');
    });
});
