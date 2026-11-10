<?php

declare(strict_types=1);

use App\Support\Maintenance\MaintenanceRetryOptions;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('builds every activity of a core workflow with retry options, so no activity is retried without end', function (): void {
    $unbounded = collect(File::allFiles(app_path('Workflows')))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all('/Temporal::newActivity\(\)(.*?)->build\(/s', $file->getContents(), $chains);

            return collect($chains[1])
                ->reject(static fn (string $chain): bool => Str::contains($chain, '->withRetryOptions('))
                ->map(static fn (): string => $file->getRelativePathname())
                ->all();
        })
        ->values()
        ->all();

    expect($unbounded)->toBe([]);
});

it('never builds retry options in a core workflow without a maximum number of attempts', function (): void {
    $unbounded = collect(File::allFiles(app_path('Workflows')))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all('/RetryOptions::new\(\)([^;]*)/s', $file->getContents(), $chains);

            return collect($chains[1])
                ->reject(static fn (string $chain): bool => Str::contains($chain, '->withMaximumAttempts('))
                ->map(static fn (): string => $file->getRelativePathname())
                ->all();
        })
        ->values()
        ->all();

    expect($unbounded)->toBe([]);
});

it('takes the maximum attempts and the non retryable failures of the shared retry options from the configuration', function (): void {
    config()->set('temporal.activity_limits.maximum_attempts', 7);
    config()->set('temporal.activity_limits.non_retryable', [AuthorizationException::class, 'NotAnException']);

    $options = MaintenanceRetryOptions::make();

    expect($options->maximumAttempts)->toBe(7)
        ->and($options->nonRetryableExceptions)->toContain(AuthorizationException::class)
        ->and($options->nonRetryableExceptions)->not->toContain('NotAnException');
});

it('never lets the schedule to close timeout of a bounded activity fall below its start to close timeout', function (): void {
    config()->set('temporal.activity_limits.schedule_to_close', 60);

    $scheduleToClose = BoundedActivityOptions::make(CarbonInterval::minutes(30))->scheduleToCloseTimeout;

    expect(CarbonInterval::instance($scheduleToClose)->totalSeconds)->toEqual(1800);
});
