<?php

declare(strict_types=1);

use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('leaves the module registry to the module installer in every package migration', function (): void {
    $offenders = collect(glob(base_path('packages/nubos/*/database/migrations/*.php')) ?: [])
        ->filter(static fn (string $path): bool => preg_match("/table\\(\\s*'modules'\\s*\\)/", (string) file_get_contents($path)) === 1)
        ->map(static fn (string $path): string => str_replace(base_path().'/', '', $path))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
