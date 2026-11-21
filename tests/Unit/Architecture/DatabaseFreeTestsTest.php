<?php

declare(strict_types=1);

use Tests\Support\ModuleRoots;

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

    foreach (ModuleRoots::directories('tests') as $directory) {
        $offenders = [...$offenders, ...($this->offendersIn)($directory)];
    }

    expect($offenders)->toBeEmpty();
});

test('the application keeps no database bound feature suite', function (): void {
    expect(is_dir(dirname(__DIR__, 2).'/Feature'))->toBeFalse();

    foreach (ModuleRoots::directories('tests/Feature') as $directory) {
        expect($directory)->toBe(null);
    }
});
