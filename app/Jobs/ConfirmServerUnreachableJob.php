<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\Silenced;

class ConfirmServerUnreachableJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CONFIRMATION_ATTEMPTS = 3;

    private const CONFIRMATION_RETRY_DELAY = 5;

    public $tries = self::CONFIRMATION_ATTEMPTS;

    public $maxExceptions = 1;

    public $timeout = 45;

    public static int $debounceSeconds = 30;

    public function __construct(public Server $server)
    {
        $this->onQueue('high');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter(300)
                ->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return self::debounceKey($this->server);
    }

    public static function dispatchIfNotQueued(Server $server): void
    {
        if (! Cache::add(self::debounceKey($server), true, now()->addSeconds(self::$debounceSeconds))) {
            return;
        }

        self::dispatch($server);
    }

    public static function debounceKey(Server $server): string
    {
        return 'confirm-server-unreachable-queued-'.$server->uuid;
    }

    public function handle(): void
    {
        try {
            if ($this->server->serverStatus() === true) {
                $this->clearDebounceLock();

                return;
            }

            if ($this->server->unreachable_notification_sent) {
                $this->clearDebounceLock();

                return;
            }

            if ($this->attempts() < self::CONFIRMATION_ATTEMPTS) {
                $this->release(self::CONFIRMATION_RETRY_DELAY);

                return;
            }

            $this->server->sendUnreachableNotification();
            $this->clearDebounceLock();
        } catch (\Throwable $exception) {
            Log::warning('ConfirmServerUnreachableJob failed', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function clearDebounceLock(): void
    {
        Cache::forget(self::debounceKey($this->server));
    }
}
