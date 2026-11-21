<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->resolvers = [
        'tests/Support/ModuleRoots.php',
        'resources/js/tests/moduleSources.ts',
        'tests/Unit/Architecture/ModuleLocationTest.php',
    ];

    /** @var callable():list<string> */
    $this->filesNamingTheDevelopmentLocation = function (): array {
        $finder = Finder::create()
            ->files()
            ->in([base_path('tests'), base_path('resources/js'), ...ModuleRoots::directories('tests')])
            ->exclude(['actions', 'routes'])
            ->name(['*.php', '*.ts', '*.vue']);

        $offenders = [];

        foreach ($finder as $file) {
            $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());

            if (in_array($relative, $this->resolvers, true)) {
                continue;
            }

            if (preg_match('#packages/(?:nubos\b|\*/)#', (string) file_get_contents((string) $file->getRealPath())) === 1) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        return $offenders;
    };
});

it('finds the modules of a suite through the module resolvers', function (): void {
    expect(($this->filesNamingTheDevelopmentLocation)())->toBe([]);
});

it('reads module sources only where the modules are developed, never from the vendor directory of an application', function (): void {
    expect(ModuleRoots::location())->toBe(base_path('packages/nubos'))
        ->and(collect(ModuleRoots::all())->reject(static fn (string $root): bool => str_starts_with($root, base_path('packages/nubos/')))->all())->toBe([]);
});
