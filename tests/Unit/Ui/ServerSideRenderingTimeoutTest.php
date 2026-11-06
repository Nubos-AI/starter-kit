<?php

declare(strict_types=1);

use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('gives up on server side rendering long before octane kills the worker', function (): void {
    $timeout = config('inertia.ssr.timeout');

    expect($timeout)->toBeInt()
        ->and($timeout)->toBeGreaterThan(0)
        ->and($timeout * 3)->toBeLessThanOrEqual((int) config('octane.max_execution_time'));
});
