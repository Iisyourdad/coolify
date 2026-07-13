<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

$originalLogger = null;

beforeEach(function () use (&$originalLogger) {
    $container = Container::getInstance();
    $originalLogger = $container->bound('log') ? $container->make('log') : null;
    $container->instance('log', new NullLogger);
});

afterEach(function () use (&$originalLogger) {
    if (is_null($originalLogger)) {
        return;
    }

    Container::getInstance()->instance('log', $originalLogger);
});

it('mirrors application published tcp ports onto the edge server for remote deployments', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 1;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 2;
    $deploymentServer->ip = '10.8.0.15';

    $application = new Application;
    $application->uuid = 'application-port-forward';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '25565:25565';

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['server_id'])->toBe(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.15:25565;');

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*docker-compose\\.yaml/", $manager->calls[0]['commands'][3], $composeMatches);
    $dockerCompose = base64_decode($composeMatches[1] ?? '');
    $parsedCompose = Yaml::parse($dockerCompose);

    expect(data_get($parsedCompose, 'services.application-application-port-forward-edge-port-proxy.container_name'))
        ->toBe('application-application-port-forward-edge-port-proxy')
        ->and(data_get($parsedCompose, 'services.application-application-port-forward-edge-port-proxy.ports'))
        ->toBe(['25565:25565'])
        ->and(collect($manager->calls[0]['commands'])->contains(
            fn (string $command) => str_contains($command, 'docker compose') && str_contains($command, ' pull')
        ))->toBeFalse();
});

it('mirrors application published udp ports onto the edge server for remote deployments', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 11;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 12;
    $deploymentServer->ip = '10.8.0.25';

    $application = new Application;
    $application->uuid = 'application-udp-port-forward';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '19132:19132/udp';

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 19132 udp;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.25:19132;');

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*docker-compose\\.yaml/", $manager->calls[0]['commands'][3], $composeMatches);
    $dockerCompose = base64_decode($composeMatches[1] ?? '');
    $parsedCompose = Yaml::parse($dockerCompose);

    expect(data_get($parsedCompose, 'services.application-application-udp-port-forward-edge-port-proxy.ports'))
        ->toBe(['19132:19132/udp']);
});

it('mirrors published compose ports for services onto the edge server', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 21;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 22;
    $deploymentServer->ip = '10.8.0.35';

    $service = new Service;
    $service->uuid = 'service-port-forward';
    $service->docker_compose_raw = <<<'YAML'
services:
  mc:
    ports:
      - "25565:25565"
      - "19132:19132/udp"
YAML;

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $warnings = $manager->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['server_id'])->toBe(21);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.35:25565;')
        ->and($nginxConf)->toContain('listen 19132 udp;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.35:19132;');
});

it('mirrors compose application published ports resolved from compose environment defaults', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 23;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 24;
    $deploymentServer->ip = '10.8.0.55';

    $application = new Application;
    $application->uuid = 'application-compose-env-default-port';
    $application->build_pack = 'dockercompose';
    $application->docker_compose_raw = <<<'YAML'
services:
  mc:
    ports:
      - "${PORT}:25565"
    environment:
      - PORT=${PORT:-25565}
YAML;

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->toContain('proxy_pass 10.8.0.55:25565;');
});

it('warns and removes stale application edge port proxy when remote host is missing', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 31;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 32;
    $deploymentServer->ip = '';

    $application = new Application;
    $application->uuid = 'application-missing-remote-host';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '25565:25565';

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('remote host is missing')
        ->and($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['commands'][0])->toContain('docker rm -f');
});

it('deletes service edge port proxy containers when no master router is configured', function () {
    $firstEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $firstEdgeProxyServer->id = 33;

    $secondEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $secondEdgeProxyServer->id = 34;

    $manager = new class($firstEdgeProxyServer, $secondEdgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        public function __construct(private Server $firstEdgeProxyServer, private Server $secondEdgeProxyServer) {}

        protected function resolveEdgeProxyServerByTeamId(?int $teamId): ?Server
        {
            return null;
        }

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect([$this->firstEdgeProxyServer, $this->secondEdgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 35;

    $service = new Service;
    $service->uuid = 'service-no-master-router';
    $service->setRelation('server', $deploymentServer);
    $service->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 67],
    ]);

    $warnings = $manager->syncService($service);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(2)
        ->and($manager->calls[0]['server_id'])->toBe(33)
        ->and($manager->calls[0]['commands'][0])->toContain('service-service-no-master-router-edge-port-proxy')
        ->and($manager->calls[1]['server_id'])->toBe(34)
        ->and($manager->calls[1]['commands'][0])->toContain('service-service-no-master-router-edge-port-proxy');
});

it('cleans up stale application edge port proxy containers on former edge servers after syncing', function () {
    $currentEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $currentEdgeProxyServer->id = 43;

    $formerEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $formerEdgeProxyServer->id = 44;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 45;
    $deploymentServer->ip = '10.8.0.65';

    $manager = new class($currentEdgeProxyServer, $formerEdgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        public function __construct(private Server $currentEdgeProxyServer, private Server $formerEdgeProxyServer) {}

        protected function resolveEdgeProxyServerByTeamId(?int $teamId): ?Server
        {
            return $this->currentEdgeProxyServer;
        }

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect([$this->currentEdgeProxyServer, $this->formerEdgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $application = new Application;
    $application->uuid = 'application-switching-master';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '25565:25565';
    $application->setRelation('destination', (object) [
        'server' => $deploymentServer,
    ]);
    $application->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 68],
    ]);

    $warnings = $manager->syncApplication($application);

    expect($warnings)->toBe([])
        ->and($manager->calls)->toHaveCount(2)
        ->and($manager->calls[0]['server_id'])->toBe(43)
        ->and($manager->calls[1]['server_id'])->toBe(44)
        ->and(collect($manager->calls[0]['commands'])->contains(
            fn (string $command) => str_contains($command, 'application-application-switching-master-edge-port-proxy')
        ))->toBeTrue()
        ->and($manager->calls[1]['commands'][0])->toContain('application-application-switching-master-edge-port-proxy');
});

it('skips reserved edge ports while keeping other published application ports', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 41;

    $deploymentServer = Mockery::mock(Server::class)->makePartial();
    $deploymentServer->id = 42;
    $deploymentServer->ip = '10.8.0.45';

    $application = new Application;
    $application->uuid = 'application-reserved-ports';
    $application->build_pack = 'nixpacks';
    $application->ports_mappings = '80:80,25565:25565,443:443';

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $warnings = $manager->syncApplicationWithServers($application, $edgeProxyServer, $deploymentServer);

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('reserved on the edge server')
        ->and($warnings[1])->toContain('reserved on the edge server')
        ->and($manager->calls)->toHaveCount(1);

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee .*nginx\\.conf/", $manager->calls[0]['commands'][2], $nginxMatches);
    $nginxConf = base64_decode($nginxMatches[1] ?? '');
    expect($nginxConf)->toContain('listen 25565;')
        ->and($nginxConf)->not->toContain('listen 80;')
        ->and($nginxConf)->not->toContain('listen 443;');
});

it('deletes application edge port proxy containers', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 51;

    $application = new Application;
    $application->uuid = 'application-delete-port-proxy';

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $manager->deleteApplicationWithServer($application, $edgeProxyServer);

    expect($manager->calls)->toHaveCount(1)
        ->and($manager->calls[0]['server_id'])->toBe(51)
        ->and($manager->calls[0]['commands'][0])->toContain('application-application-delete-port-proxy-edge-port-proxy')
        ->and($manager->calls[0]['commands'][0])->toEndWith('>/dev/null 2>&1 || true');
});

it('deletes service edge port proxy containers from all team traefik servers', function () {
    $firstEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $firstEdgeProxyServer->id = 61;

    $secondEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $secondEdgeProxyServer->id = 62;

    $service = new Service;
    $service->uuid = 'service-delete-port-proxy-all-servers';
    $service->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 61],
    ]);

    $manager = new class($firstEdgeProxyServer, $secondEdgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        public function __construct(private Server $firstEdgeProxyServer, private Server $secondEdgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect([$this->firstEdgeProxyServer, $this->secondEdgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $manager->deleteService($service);

    expect($manager->calls)->toHaveCount(2)
        ->and($manager->calls[0]['server_id'])->toBe(61)
        ->and($manager->calls[0]['commands'][0])->toContain('service-service-delete-port-proxy-all-servers-edge-port-proxy')
        ->and($manager->calls[1]['server_id'])->toBe(62)
        ->and($manager->calls[1]['commands'][0])->toContain('service-service-delete-port-proxy-all-servers-edge-port-proxy');
});

it('deletes application edge port proxy containers from all team traefik servers', function () {
    $firstEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $firstEdgeProxyServer->id = 71;

    $secondEdgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $secondEdgeProxyServer->id = 72;

    $application = new Application;
    $application->uuid = 'application-delete-port-proxy-all-servers';
    $application->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 71],
    ]);

    $manager = new class($firstEdgeProxyServer, $secondEdgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public array $calls = [];

        public function __construct(private Server $firstEdgeProxyServer, private Server $secondEdgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect([$this->firstEdgeProxyServer, $this->secondEdgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            $this->calls[] = [
                'server_id' => $server->id,
                'commands' => $commands,
                'throw_error' => $throwError,
            ];

            return null;
        }
    };

    $manager->deleteApplication($application);

    expect($manager->calls)->toHaveCount(2)
        ->and($manager->calls[0]['server_id'])->toBe(71)
        ->and($manager->calls[0]['commands'][0])->toContain('application-application-delete-port-proxy-all-servers-edge-port-proxy')
        ->and($manager->calls[1]['server_id'])->toBe(72)
        ->and($manager->calls[1]['commands'][0])->toContain('application-application-delete-port-proxy-all-servers-edge-port-proxy');
});

it('returns cleanup failure details when deleting application edge port proxy hits an ssh error', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 81;
    $edgeProxyServer->name = 'edge-proxy-81';

    $application = new Application;
    $application->uuid = 'application-delete-port-proxy-ssh-failure';
    $application->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 81],
    ]);

    $manager = new class($edgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public function __construct(private Server $edgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect([$this->edgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            throw new RuntimeException('ssh: connect to host 10.0.0.10 port 22: Connection timed out');
        }
    };

    $failures = $manager->deleteApplication($application);

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('Failed to delete edge port proxy for application application-delete-port-proxy-ssh-failure')
        ->and($failures[0])->toContain('edge-proxy-81 (81)')
        ->and($failures[0])->toContain('Connection timed out');
});

it('treats missing edge port proxy containers as already cleaned up', function () {
    $edgeProxyServer = Mockery::mock(Server::class)->makePartial();
    $edgeProxyServer->id = 82;
    $edgeProxyServer->name = 'edge-proxy-82';

    $application = new Application;
    $application->uuid = 'application-delete-missing-port-proxy';
    $application->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 82],
    ]);

    $manager = new class($edgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public function __construct(private Server $edgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): Collection
        {
            return collect([$this->edgeProxyServer]);
        }

        protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
        {
            throw new RuntimeException('Error response from daemon: No such container: application-delete-missing-port-proxy-edge-port-proxy');
        }
    };

    expect($manager->deleteApplication($application))->toBe([]);
});
