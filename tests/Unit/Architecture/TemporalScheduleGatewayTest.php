<?php

declare(strict_types=1);

use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->root = base_path();

    $this->scanDirectories = [$this->root.'/app', ...(glob($this->root.'/vendor/nubos/*/src', GLOB_ONLYDIR) ?: [])];

    $this->tokensOf = function (string $source): array {
        return array_values(array_filter(
            token_get_all($source),
            fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));
    };

    $this->phpFilesIn = function (string $directory): array {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    };

    $this->relativePath = function (string $directory, string $file): string {
        $prefix = str_starts_with($file, $this->root.'/') ? $this->root : dirname($directory);

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($prefix) + 1));
    };
});

test('temporal schedules are only created or fetched inside the gateway and its container binding', function (): void {
    $allowed = [
        'app/Support/Temporal/TemporalScheduleGateway.php',
        'app/Providers/AppServiceProvider.php',
    ];
    $callers = [];
    $files = [];
    foreach ($this->scanDirectories as $directory) {
        array_push($files, ...($this->phpFilesIn)($directory));
    }

    foreach ($files as $file) {
        $tokens = ($this->tokensOf)((string) file_get_contents($file));

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_STRING && in_array($token[1], ['createSchedule', 'getHandle'], true) && ($tokens[$index + 1] ?? null) === '(') {
                $callers[] = ($this->relativePath)($this->root.'/app', $file).':'.$token[2];
            }
        }
    }

    $outside = array_values(array_filter(
        $callers,
        fn (string $caller): bool => !in_array(explode(':', $caller)[0], $allowed, true),
    ));

    expect($callers)->not->toBe([])
        ->and($outside)->toBe([], 'schedule calls that bypass TemporalScheduleGateway: '.implode(', ', $outside));
});
