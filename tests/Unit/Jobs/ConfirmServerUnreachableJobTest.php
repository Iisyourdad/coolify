<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ConfirmServerUnreachableJob;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ConfirmServerUnreachableJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_job_has_correct_retry_configuration(): void
    {
        $job = new ConfirmServerUnreachableJob(Mockery::mock(Server::class));

        $this->assertSame(3, $job->tries);
        $this->assertSame(1, $job->maxExceptions);
        $this->assertSame(45, $job->timeout);
    }

    public function test_job_releases_for_another_confirmation_attempt_before_notifying(): void
    {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')
            ->with('uuid')
            ->andReturn('server-unreachable-confirmation');
        $server->shouldReceive('getAttribute')
            ->with('unreachable_notification_sent')
            ->andReturnFalse();
        $server->shouldReceive('serverStatus')
            ->once()
            ->andReturnFalse();
        $server->shouldReceive('sendUnreachableNotification')->never();

        Cache::spy();

        $job = $this->makeJob($server, 1);
        $job->handle();

        Cache::shouldNotHaveReceived('forget');
        $this->assertSame(5, $job->releasedDelay);
    }

    public function test_job_sends_notification_on_the_final_failed_attempt(): void
    {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')
            ->with('uuid')
            ->andReturn('server-unreachable-confirmation');
        $server->shouldReceive('getAttribute')
            ->with('unreachable_notification_sent')
            ->andReturnFalse();
        $server->shouldReceive('serverStatus')
            ->once()
            ->andReturnFalse();
        $server->shouldReceive('sendUnreachableNotification')
            ->once();

        Cache::shouldReceive('forget')
            ->once()
            ->with(ConfirmServerUnreachableJob::debounceKey($server))
            ->andReturnTrue();

        $job = $this->makeJob($server, 3);
        $job->handle();

        $this->assertNull($job->releasedDelay);
    }

    public function test_job_clears_the_debounce_when_the_server_recovers(): void
    {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')
            ->with('uuid')
            ->andReturn('server-unreachable-confirmation');
        $server->shouldReceive('getAttribute')
            ->with('unreachable_notification_sent')
            ->andReturnFalse();
        $server->shouldReceive('serverStatus')
            ->once()
            ->andReturnTrue();
        $server->shouldReceive('sendUnreachableNotification')->never();

        Cache::shouldReceive('forget')
            ->once()
            ->with(ConfirmServerUnreachableJob::debounceKey($server))
            ->andReturnTrue();

        $job = $this->makeJob($server, 2);
        $job->handle();

        $this->assertNull($job->releasedDelay);
    }

    private function makeJob(Server $server, int $attempts): ConfirmServerUnreachableJob
    {
        return new class($server, $attempts) extends ConfirmServerUnreachableJob
        {
            public ?int $releasedDelay = null;

            public function __construct(Server $server, public int $fakeAttempts)
            {
                parent::__construct($server);
            }

            public function attempts(): int
            {
                return $this->fakeAttempts;
            }

            public function release($delay = 0): void
            {
                $this->releasedDelay = $delay;
            }
        };
    }
}
