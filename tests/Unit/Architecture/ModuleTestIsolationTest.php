<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string, string): list<string> */
    $this->moduleTestsMatching = static function (string $fileNames, string $pattern): array {
        $directories = ModuleRoots::directories('tests');

        if ($directories === []) {
            return [];
        }

        $offenders = [];

        foreach (Finder::create()->files()->in($directories)->name($fileNames) as $file) {
            if (preg_match($pattern, (string) file_get_contents((string) $file->getRealPath())) === 1) {
                $offenders[] = ModuleRoots::relativePath((string) $file->getRealPath());
            }
        }

        sort($offenders);

        return $offenders;
    };
});

it('keeps every module php test independent of the test classes of the application it is installed into', function (): void {
    expect(($this->moduleTestsMatching)('*.php', '/(?<![\w\\\\])\\\\?Tests\\\\/'))->toBe([]);
});

it('keeps every module frontend test independent of the test helpers of the application it is installed into', function (): void {
    expect(($this->moduleTestsMatching)('*.ts', "#from '@/tests/#"))->toBe([]);
});

it('boots the test suite of every module through a bootstrap of its own', function (): void {
    $offenders = collect(ModuleRoots::all())
        ->filter(static fn (string $root): bool => is_file("{$root}/phpunit.xml"))
        ->reject(static fn (string $root): bool => str_contains((string) file_get_contents("{$root}/phpunit.xml"), 'bootstrap="tests/bootstrap.php"')
            && is_file("{$root}/tests/bootstrap.php"))
        ->keys()
        ->all();

    expect($offenders)->toBe([]);
});
