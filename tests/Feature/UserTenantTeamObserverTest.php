<?php

declare(strict_types=1);

use App\Jobs\CleanupUserTeamMembershipsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches cleanup job when user is deleted', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $userId = $user->id;

    $user->delete();

    Queue::assertPushed(CleanupUserTeamMembershipsJob::class, function ($job) use ($userId): bool {
        return (new ReflectionProperty($job, 'userId'))->getValue($job) === $userId;
    });
});

it('does not run cleanup synchronously', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $user->delete();

    Queue::assertPushed(CleanupUserTeamMembershipsJob::class);
    Queue::assertCount(1);
});
