<?php

declare(strict_types=1);

$packageDirectories = glob(__DIR__.'/vendor/nubos/*', GLOB_ONLYDIR) ?: [];

if ($packageDirectories === []) {
    return [];
}

$installedPackages = array_map('basename', $packageDirectories);

$optionalPackageSources = array_values(array_filter(
    [
        ...glob(__DIR__.'/packages/nubos/*/src', GLOB_ONLYDIR) ?: [],
        ...glob(__DIR__.'/packages/nubos/*/database', GLOB_ONLYDIR) ?: [],
    ],
    static fn (string $directory): bool => !in_array(basename(dirname($directory)), $installedPackages, true),
));

return [
    'parameters' => [
        'tmpDir' => sys_get_temp_dir().'/phpstan/'.md5(implode(',', $installedPackages)),
        'databaseMigrationsPath' => glob(__DIR__.'/vendor/nubos/*/database/migrations', GLOB_ONLYDIR) ?: [],
        'paths' => [__DIR__.'/vendor/nubos/'],
        'scanDirectories' => $optionalPackageSources,
        'excludePaths' => [
            'analyse' => [
                __DIR__.'/vendor/nubos/*/config/*',
                __DIR__.'/vendor/nubos/*/lang/*',
                __DIR__.'/vendor/nubos/*/resources/*',
                __DIR__.'/vendor/nubos/*/routes/*',
                __DIR__.'/vendor/nubos/*/tests/*',
            ],
        ],
    ],
];
