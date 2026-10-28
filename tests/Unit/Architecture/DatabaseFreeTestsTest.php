<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;

arch('the database free helpers never pull in a database lifecycle trait')
    ->expect('Tests\Support')
    ->not->toUse([
        RefreshDatabase::class,
        DatabaseMigrations::class,
        DatabaseTransactions::class,
        DatabaseTruncation::class,
    ]);

beforeEach(function (): void {
    $this->forbidden = [
        'RefreshDatabase',
        'DatabaseMigrations',
        'DatabaseTransactions',
        'DatabaseTruncation',
        '::factory(',
        'assertDatabaseHas',
        'assertDatabaseMissing',
        'WithTemporal',
    ];

    /** @var callable(string):list<string> */
    $this->offendersIn = function (string $directory): array {
        if (!is_dir($directory)) {
            return [];
        }

        $offenders = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            if ($file->getPathname() === __FILE__) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($this->forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file->getPathname().' → '.$needle;
                }
            }
        }

        return $offenders;
    };
});

test('no test in the application reaches for a database', function (): void {
    expect(($this->offendersIn)(dirname(__DIR__, 2)))->toBeEmpty();
});

test('no test in a package reaches for a database', function (): void {
    $offenders = [];

    foreach ((array) glob(dirname(__DIR__, 3).'/packages/nubos/*/tests') as $directory) {
        $offenders = [...$offenders, ...($this->offendersIn)((string) $directory)];
    }

    expect($offenders)->toBeEmpty();
});

test('the application keeps no database bound feature suite', function (): void {
    expect(is_dir(dirname(__DIR__, 2).'/Feature'))->toBeFalse();

    foreach ((array) glob(dirname(__DIR__, 3).'/packages/nubos/*/tests/Feature') as $directory) {
        expect($directory)->toBe(null);
    }
});
