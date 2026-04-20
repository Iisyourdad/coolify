<?php

use App\Jobs\ConfirmServerUnreachableJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('keeps a server marked unreachable after the first failed reachability check', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);

    $server->settings->update([
        'is_reachable' => false,
        'is_usable' => false,
        'unreachable_count' => 0,
        'unreachable_notification_sent' => false,
    ]);

    $server->refresh();
    $server->isReachableChanged();
    $server->refresh();

    expect($server->settings->is_reachable)->toBeFalse();
    expect($server->unreachable_count)->toBe(1);

    Queue::assertNothingPushed();
});

it('queues a confirmation job after repeated unreachable checks', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);

    $server->settings->update([
        'is_reachable' => false,
        'is_usable' => false,
        'unreachable_count' => 0,
        'unreachable_notification_sent' => false,
    ]);

    $server->refresh();
    $server->isReachableChanged();
    $server->refresh();
    $server->isReachableChanged();

    Queue::assertPushed(ConfirmServerUnreachableJob::class, function (ConfirmServerUnreachableJob $job) use ($server) {
        return $job->server->is($server);
    });
});
