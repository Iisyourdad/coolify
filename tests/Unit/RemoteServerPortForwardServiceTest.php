<?php

use App\Models\Server;
use App\Services\PublishedPortMappingParser;
use App\Services\RemotePortForwardConfigurationBuilder;
use App\Services\RemoteServerPortForwardService;
use App\Services\RemoteServerTargetResolver;

function portForwardServiceSpy(): RemoteServerPortForwardService
{
    return new class(app(RemoteServerTargetResolver::class), app(PublishedPortMappingParser::class), app(RemotePortForwardConfigurationBuilder::class)) extends RemoteServerPortForwardService
    {
        public array $commands = [];

        public function inspectWrite(Server $server): void
        {
            $this->write($server, 1, 'application', 'game', '10.8.0.15', collect([['published' => 25565, 'protocol' => 'tcp']]));
        }

        public function inspectReserved(Server $server): array
        {
            return $this->withoutReserved($server, 'application', 'game', collect([
                ['published' => 80, 'protocol' => 'tcp'],
                ['published' => 25565, 'protocol' => 'tcp'],
                ['published' => 25565, 'protocol' => 'udp'],
            ]));
        }

        protected function run(Server $server, array $commands): ?string
        {
            $this->commands = $commands;
            return null;
        }
    };
}

it('validates temporary forwarder files before replacing known-good files', function () {
    $service = portForwardServiceSpy();
    $service->inspectWrite(new Server(['uuid' => 'master']));
    $commands = implode("\n", $service->commands);

    expect($commands)->toContain('set -e', 'docker compose', 'docker run --rm --network none', 'nginx -t', '.previous-')
        ->and(strpos($commands, 'nginx.conf.tmp-'))->toBeLessThan(strpos($commands, 'mv -f'));
});

it('reports reserved TCP ports while allowing TCP and UDP on the same game port', function () {
    $server = new Server(['proxy' => ['type' => 'TRAEFIK']]);
    [$allowed, $warnings] = portForwardServiceSpy()->inspectReserved($server);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('tcp:80')
        ->and($allowed->all())->toBe([
            ['published' => 25565, 'protocol' => 'tcp'],
            ['published' => 25565, 'protocol' => 'udp'],
        ]);
});
