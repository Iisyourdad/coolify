<?php

namespace App\Console\Commands;

use App\Enums\ActivityTypes;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyTypes;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\PullChangelog;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\User;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class Init extends Command
{
    protected $signature = 'app:init';

    protected $description = 'Cleanup instance related stuffs';

    public $servers = null;

    public InstanceSettings $settings;

    public function handle()
    {
        // Skip optimize warmup under tests; it can reset the in-memory app and break DB-backed assertions.
        if (! app()->runningUnitTests()) {
            Artisan::call('optimize:clear');
            Artisan::call('optimize');
        }

        try {
            $this->pullTemplatesFromCDN();
        } catch (\Throwable $e) {
            echo "Could not pull templates from CDN: {$e->getMessage()}\n";
        }

        try {
            $this->pullChangelogFromGitHub();
        } catch (\Throwable $e) {
            echo "Could not changelogs from github: {$e->getMessage()}\n";
        }

        try {
            $this->pullHelperImage();
        } catch (\Throwable $e) {
            echo "Error in pullHelperImage command: {$e->getMessage()}\n";
        }

        if (isCloud()) {
            return;
        }

        $this->settings = instanceSettings();
        $this->servers = Server::all();

        $do_not_track = data_get($this->settings, 'do_not_track', true);
        if ($do_not_track == false) {
            $this->sendAliveSignal();
        }
        get_public_ips();

        // Backward compatibility
        $this->replaceSlashInEnvironmentName();
        $this->restoreCoolifyDbBackup();
        $this->updateUserEmails();
        //
        $this->updateTraefikLabels();
        $this->cleanupUnusedNetworkFromCoolifyProxy();

        try {
            $this->call('cleanup:redis', ['--restart' => true, '--clear-locks' => true]);
        } catch (\Throwable $e) {
            echo "Error in cleanup:redis command: {$e->getMessage()}\n";
        }
        try {
            $this->call('cleanup:names');
        } catch (\Throwable $e) {
            echo "Error in cleanup:names command: {$e->getMessage()}\n";
        }
        try {
            $this->call('cleanup:stucked-resources');
        } catch (\Throwable $e) {
            echo "Error in cleanup:stucked-resources command: {$e->getMessage()}\n";
            echo "Continuing with initialization - cleanup errors will not prevent Coolify from starting\n";
        }
        try {
            $this->cleanupStuckApplicationDeployments();
        } catch (\Throwable $e) {
            echo "Could not cleanup inprogress deployments: {$e->getMessage()}\n";
        }
        try {
            $this->resumeQueuedApplicationDeployments();
        } catch (\Throwable $e) {
            echo "Could not resume queued deployments: {$e->getMessage()}\n";
        }

        try {
            $updatedTaskCount = ScheduledTaskExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedTaskCount > 0) {
                echo "Marked {$updatedTaskCount} stuck scheduled task executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck scheduled task executions: {$e->getMessage()}\n";
        }

        try {
            $updatedBackupCount = ScheduledDatabaseBackupExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedBackupCount > 0) {
                echo "Marked {$updatedBackupCount} stuck database backup executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck database backup executions: {$e->getMessage()}\n";
        }

        try {
            $localhost = $this->servers->where('id', 0)->first();
            if ($localhost) {
                $localhost->setupDynamicProxyConfiguration();
            }
        } catch (\Throwable $e) {
            echo "Could not setup dynamic configuration: {$e->getMessage()}\n";
        }

        try {
            $this->rebuildRemoteProxyConfigurations();
        } catch (\Throwable $e) {
            echo "Could not rebuild remote proxy configurations: {$e->getMessage()}\n";
        }

        if (! is_null(config('constants.coolify.autoupdate', null))) {
            if (config('constants.coolify.autoupdate') == true) {
                echo "Enabling auto-update\n";
                $this->settings->update(['is_auto_update_enabled' => true]);
            } else {
                echo "Disabling auto-update\n";
                $this->settings->update(['is_auto_update_enabled' => false]);
            }
        }
    }

    private function pullHelperImage()
    {
        CheckHelperImageJob::dispatch();
    }

    private function cleanupStuckApplicationDeployments(): void
    {
        $stuckDeployments = ApplicationDeploymentQueue::query()
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->get(['id']);

        if ($stuckDeployments->isEmpty()) {
            return;
        }

        $finishedAt = Carbon::now();

        ApplicationDeploymentQueue::whereIn('id', $stuckDeployments->pluck('id')->all())
            ->update([
                'status' => ApplicationDeploymentStatus::FAILED->value,
                'finished_at' => $finishedAt,
            ]);

        echo "Marked {$stuckDeployments->count()} stuck deployments as failed\n";
    }

    private function resumeQueuedApplicationDeployments(): void
    {
        $queuedServerIds = ApplicationDeploymentQueue::query()
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->whereNotNull('server_id')
            ->pluck('server_id')
            ->unique()
            ->values();

        if ($queuedServerIds->isEmpty()) {
            return;
        }

        $servers = Server::query()
            ->whereIn('id', $queuedServerIds->all())
            ->get();

        foreach ($servers as $server) {
            next_after_cancel($server);
        }

        echo "Rescanned queued deployments on {$servers->count()} servers\n";
    }

    /**
     * Regenerate the master-domain (edge) route and port-forward files for every
     * application and service on a Coolify restart/update.
     *
     * This is what lets an already-connected server pick up master-domain routing
     * without being disconnected/reconnected or every resource being redeployed:
     * once an instance is updated, app:init reconciles the generated files on the
     * edge proxy. Teams with any Traefik server are reconciled even when routing is
     * currently disabled, because those servers can still contain files from a former
     * master router. The per-resource sync methods are idempotent and remove stale
     * files when routing no longer applies, so re-running this on every startup is safe.
     */
    private function rebuildRemoteProxyConfigurations(): void
    {
        $edgeRoutingTeamIds = Server::query()
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->pluck('team_id')
            ->filter()
            ->map(fn ($teamId) => (int) $teamId)
            ->unique()
            ->values();

        if ($edgeRoutingTeamIds->isEmpty()) {
            return;
        }

        $routeService = app(EdgeProxyRemoteRouteService::class);
        $portForwardService = app(EdgeProxyRemotePortForwardService::class);
        $rebuiltCount = 0;

        Application::query()
            ->with(['destination.server', 'environment.project', 'settings'])
            ->chunkById(100, function (Collection $applications) use ($routeService, $portForwardService, $edgeRoutingTeamIds, &$rebuiltCount) {
                foreach ($applications as $application) {
                    $teamId = data_get($application, 'environment.project.team_id');
                    if (is_null($teamId) || ! $edgeRoutingTeamIds->contains((int) $teamId)) {
                        continue;
                    }

                    try {
                        $routeService->syncApplication($application);
                        $portForwardService->syncApplication($application);
                        $rebuiltCount++;
                    } catch (\Throwable $e) {
                        echo "Could not rebuild remote proxy configuration for application {$application->uuid}: {$e->getMessage()}\n";
                    }
                }
            });

        Service::query()
            ->with(['destination.server', 'environment.project', 'server', 'applications'])
            ->chunkById(100, function (Collection $services) use ($routeService, $portForwardService, $edgeRoutingTeamIds, &$rebuiltCount) {
                foreach ($services as $service) {
                    $teamId = data_get($service, 'environment.project.team_id');
                    if (is_null($teamId) || ! $edgeRoutingTeamIds->contains((int) $teamId)) {
                        continue;
                    }

                    try {
                        $routeService->syncService($service);
                        $portForwardService->syncService($service);
                        $rebuiltCount++;
                    } catch (\Throwable $e) {
                        echo "Could not rebuild remote proxy configuration for service {$service->uuid}: {$e->getMessage()}\n";
                    }
                }
            });

        if ($rebuiltCount > 0) {
            echo "Rebuilt remote proxy configurations for {$rebuiltCount} resources\n";
        }

        $this->pruneOrphanRemoteProxyConfigurations($routeService);
    }

    /**
     * Remove generated edge route files for resources that no longer exist, so deleted
     * applications/services stop returning 503 from the master domain router.
     */
    private function pruneOrphanRemoteProxyConfigurations(EdgeProxyRemoteRouteService $routeService): void
    {
        $edgeProxyServers = Server::query()
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->get();

        if ($edgeProxyServers->isEmpty()) {
            return;
        }

        $prunedCount = 0;

        foreach ($edgeProxyServers as $edgeProxyServer) {
            try {
                $validApplicationUuids = Application::query()
                    ->whereHas('environment.project', fn ($query) => $query->where('team_id', $edgeProxyServer->team_id))
                    ->pluck('uuid')
                    ->all();
                $validServiceUuids = Service::query()
                    ->whereHas('environment.project', fn ($query) => $query->where('team_id', $edgeProxyServer->team_id))
                    ->pluck('uuid')
                    ->all();
                $warnings = $routeService->pruneOrphanRouteFiles($edgeProxyServer, $validApplicationUuids, $validServiceUuids);
                $prunedCount += count($warnings);
            } catch (\Throwable $e) {
                echo "Could not prune orphan edge route files on server {$edgeProxyServer->id}: {$e->getMessage()}\n";
            }
        }

        if ($prunedCount > 0) {
            echo "Pruned {$prunedCount} orphan edge route files\n";
        }
    }

    private function pullTemplatesFromCDN()
    {
        $response = Http::retry(3, 1000, throw: false)
            ->timeout(60)
            ->connectTimeout(10)
            ->get(config('constants.services.official'));
        if ($response->successful()) {
            store_service_templates_bundle($response->body());
        }
    }

    private function pullChangelogFromGitHub()
    {
        try {
            PullChangelog::dispatch();
            echo "Changelog fetch initiated\n";
        } catch (\Throwable $e) {
            echo "Could not fetch changelog from GitHub: {$e->getMessage()}\n";
        }
    }

    private function updateUserEmails()
    {
        try {
            User::whereRaw('email ~ \'[A-Z]\'')->get()->each(function (User $user) {
                $user->update(['email' => $user->email]);
            });
        } catch (\Throwable $e) {
            echo "Error in updating user emails: {$e->getMessage()}\n";
        }
    }

    private function updateTraefikLabels()
    {
        try {
            Server::where('proxy->type', 'TRAEFIK_V2')->update(['proxy->type' => 'TRAEFIK']);
        } catch (\Throwable $e) {
            echo "Error in updating traefik labels: {$e->getMessage()}\n";
        }
    }

    private function cleanupUnusedNetworkFromCoolifyProxy()
    {
        foreach ($this->servers as $server) {
            if (! $server->isFunctional()) {
                continue;
            }
            if (! $server->isProxyShouldRun()) {
                continue;
            }
            try {
                ['networks' => $networks, 'allNetworks' => $allNetworks] = collectDockerNetworksByServer($server);
                $removeNetworks = $allNetworks->diff($networks);
                $commands = collect();
                foreach ($removeNetworks as $network) {
                    $safe = escapeshellarg($network);
                    $out = instant_remote_process(["docker network inspect -f json {$safe} | jq '.[].Containers | if . == {} then null else . end'"], $server, false);
                    if (empty($out)) {
                        $commands->push("docker network disconnect {$safe} coolify-proxy >/dev/null 2>&1 || true");
                        $commands->push("docker network rm {$safe} >/dev/null 2>&1 || true");
                    } else {
                        $data = collect(json_decode($out, true));
                        if ($data->count() === 1) {
                            // If only coolify-proxy itself is connected to that network (it should not be possible, but who knows)
                            $isCoolifyProxyItself = data_get($data->first(), 'Name') === 'coolify-proxy';
                            if ($isCoolifyProxyItself) {
                                $commands->push("docker network disconnect {$safe} coolify-proxy >/dev/null 2>&1 || true");
                                $commands->push("docker network rm {$safe} >/dev/null 2>&1 || true");
                            }
                        }
                    }
                }
                if ($commands->isNotEmpty()) {
                    remote_process(command: $commands, type: ActivityTypes::INLINE->value, server: $server, ignore_errors: false);
                }
            } catch (\Throwable $e) {
                echo "Error in cleaning up unused networks from coolify proxy: {$e->getMessage()}\n";
            }
        }
    }

    private function restoreCoolifyDbBackup()
    {
        if (version_compare('4.0.0-beta.179', config('constants.coolify.version'), '<=')) {
            try {
                $database = StandalonePostgresql::withTrashed()->find(0);
                if ($database && $database->trashed()) {
                    $database->restore();
                    $scheduledBackup = ScheduledDatabaseBackup::find(0);
                    if (! $scheduledBackup) {
                        ScheduledDatabaseBackup::create([
                            'id' => 0,
                            'enabled' => true,
                            'save_s3' => false,
                            'frequency' => '0 0 * * *',
                            'database_id' => $database->id,
                            'database_type' => StandalonePostgresql::class,
                            'team_id' => 0,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                echo "Error in restoring coolify db backup: {$e->getMessage()}\n";
            }
        }
    }

    private function sendAliveSignal()
    {
        $id = config('app.id');
        $version = config('constants.coolify.version');
        try {
            Http::get("https://undead.coolify.io/v4/alive?appId=$id&version=$version");
        } catch (\Throwable $e) {
            echo "Error in sending live signal: {$e->getMessage()}\n";
        }
    }

    private function replaceSlashInEnvironmentName()
    {
        if (version_compare('4.0.0-beta.298', config('constants.coolify.version'), '<=')) {
            $environments = Environment::all();
            foreach ($environments as $environment) {
                if (str_contains($environment->name, '/')) {
                    $environment->name = str_replace('/', '-', $environment->name);
                    $environment->save();
                }
            }
        }
    }
}
