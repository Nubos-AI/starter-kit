<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string):list<string> */
    $this->migrationNamesIn = static function (string $directory): array {
        $paths = glob("{$directory}/*.php") ?: [];

        return collect($paths)
            ->map(static fn (string $path): string => basename($path))
            ->sort()
            ->values()
            ->all();
    };

    /** @var callable(list<string>, string):list<string> */
    $this->numberingViolations = static function (array $names, string $prefix): array {
        $violations = [];

        foreach ($names as $position => $name) {
            $counter = Str::padLeft((string) $position, 6, '0');
            $expected = "{$prefix}_01_01_{$counter}_";

            if (!str_starts_with($name, $expected)) {
                $violations[] = "{$name} (erwartet {$expected}…)";
            }
        }

        return $violations;
    };

    /** @var callable():array<string, array{prefix: string, isPaid: bool, names: list<string>}> */
    $this->packagesWithMigrations = function (): array {
        $packages = [];

        foreach (glob(base_path('packages/nubos/*'), GLOB_ONLYDIR) ?: [] as $packagePath) {
            $names = ($this->migrationNamesIn)("{$packagePath}/database/migrations");

            if ($names === []) {
                continue;
            }

            $composer = json_decode((string) file_get_contents("{$packagePath}/composer.json"), true);

            $packages[basename($packagePath)] = [
                'prefix' => substr($names[0], 0, 4),
                'isPaid' => data_get($composer, 'license') === 'LicenseRef-Nubos',
                'names' => $names,
            ];
        }

        return $packages;
    };
});

it('numbers the core migrations continuously under the 0001 prefix', function (): void {
    $names = ($this->migrationNamesIn)(database_path('migrations'));

    expect($names)->not->toBeEmpty()
        ->and(($this->numberingViolations)($names, '0001'))->toBe([]);
});

it('numbers every package continuously under one package prefix', function (): void {
    $violations = collect(($this->packagesWithMigrations)())
        ->flatMap(fn (array $package, string $name): array => collect(($this->numberingViolations)($package['names'], $package['prefix']))
            ->map(static fn (string $violation): string => "{$name}: {$violation}")
            ->all())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('puts free packages into the 1xxx block and paid packages into the 2xxx block', function (): void {
    $misplaced = collect(($this->packagesWithMigrations)())
        ->reject(static fn (array $package): bool => Str::isMatch($package['isPaid'] ? '/^2(?!000)\d{3}$/' : '/^1(?!000)\d{3}$/', $package['prefix']))
        ->map(static fn (array $package): string => $package['prefix'])
        ->all();

    expect($misplaced)->toBe([]);
});

it('gives every package its own prefix', function (): void {
    $prefixes = collect(($this->packagesWithMigrations)())->pluck('prefix');

    expect($prefixes->duplicates()->values()->all())->toBe([]);
});
