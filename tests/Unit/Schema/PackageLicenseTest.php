<?php

declare(strict_types=1);

use Composer\Spdx\SpdxLicenses;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->packageLicenses = static fn (): array => collect(glob(base_path('packages/nubos/*'), GLOB_ONLYDIR) ?: [])
        ->mapWithKeys(static fn (string $path): array => [
            basename($path) => json_decode((string) file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['license'] ?? null,
        ])
        ->all();
});

it('licenses every package as free MIT or as the commercial Nubos licence', function (): void {
    $offenders = collect(($this->packageLicenses)())
        ->reject(static fn (?string $license): bool => in_array($license, ['MIT', 'LicenseRef-Nubos'], true))
        ->all();

    expect($offenders)->toBe([]);
});

it('declares only licence identifiers composer accepts as SPDX', function (): void {
    $spdx = new SpdxLicenses;

    $offenders = collect(($this->packageLicenses)())
        ->reject(static fn (?string $license): bool => $license !== null && $spdx->validate($license))
        ->all();

    expect($offenders)->toBe([]);
});

it('ships the licence text with every package', function (): void {
    $offenders = collect(glob(base_path('packages/nubos/*'), GLOB_ONLYDIR) ?: [])
        ->reject(static fn (string $path): bool => is_file($path.'/LICENSE'))
        ->map(static fn (string $path): string => basename($path))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
