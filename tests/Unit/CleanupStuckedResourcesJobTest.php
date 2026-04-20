<?php

use App\Jobs\CleanupStuckedResourcesJob;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::forget(CleanupStuckedResourcesJob::debounceKey());
});

it('debounces stucked resource cleanup job dispatches', function () {
    Queue::fake();

    CleanupStuckedResourcesJob::dispatchIfNotQueued();
    CleanupStuckedResourcesJob::dispatchIfNotQueued();

    Queue::assertPushed(CleanupStuckedResourcesJob::class, 1);
});

it('queues stucked resource cleanup through the dedicated job', function () {
    Queue::fake();

    $application = new Application;
    $application->uuid = 'application-cleanup-queue-job';

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        public function queueCleanupNow(): void
        {
            $this->queueStuckedResourcesCleanup();
        }
    };

    $job->queueCleanupNow();

    Queue::assertPushed(CleanupStuckedResourcesJob::class, 1);
});
