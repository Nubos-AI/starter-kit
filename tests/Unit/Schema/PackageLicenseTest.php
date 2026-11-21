<?php

declare(strict_types=1);

use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->packageLicenses = static fn (): array => collect(ModuleRoots::all())
        ->map(static fn (string $path): ?string => json_decode((string) file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['license'] ?? null)
        ->all();
});

it('licenses every package as free MIT or as the commercial Nubos licence', function (): void {
    $offenders = collect(($this->packageLicenses)())
        ->reject(static fn (?string $license): bool => in_array($license, ['MIT', 'LicenseRef-Nubos'], true))
        ->all();

    expect($offenders)->toBe([]);
});

it('ships the licence text with every package', function (): void {
    $offenders = collect(ModuleRoots::all())
        ->reject(static fn (string $path): bool => is_file($path.'/LICENSE'))
        ->keys()
        ->all();

    expect($offenders)->toBe([]);
});
