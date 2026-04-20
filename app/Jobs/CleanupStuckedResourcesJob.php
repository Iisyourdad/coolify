<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\Silenced;

class CleanupStuckedResourcesJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300;

    public static int $debounceSeconds = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter(300)->dontRelease()];
    }

    public function uniqueId(): string
    {
        return self::debounceKey();
    }

    public static function dispatchIfNotQueued(): void
    {
        if (! Cache::add(self::debounceKey(), true, now()->addSeconds(self::$debounceSeconds))) {
            return;
        }

        self::dispatch();
    }

    public static function debounceKey(): string
    {
        return 'cleanup-stucked-resources-queued';
    }

    public function handle(): void
    {
        try {
            Artisan::call('cleanup:stucked-resources');
        } catch (\Throwable $exception) {
            Log::warning('CleanupStuckedResourcesJob failed', [
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
