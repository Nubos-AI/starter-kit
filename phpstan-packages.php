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

$developedPackages = array_map('basename', glob(__DIR__.'/packages/nubos/*', GLOB_ONLYDIR) ?: []);

$suggestedPackages = array_merge(...array_map(
    static fn (string $directory): array => array_keys(
        json_decode((string) @file_get_contents("{$directory}/composer.json"), true)['suggest'] ?? [],
    ),
    $packageDirectories,
));

$absentNamespaces = array_values(array_unique(array_map(
    static fn (string $package): string => ucfirst(basename($package)),
    array_filter(
        $suggestedPackages,
        static fn (string $package): bool => str_starts_with($package, 'nubos/')
            && !in_array(basename($package), [...$installedPackages, ...$developedPackages], true),
    ),
)));

$declaresAbsentType = static function (string $file) use ($absentNamespaces): bool {
    $source = (string) file_get_contents($file);
    $namespaces = implode('|', $absentNamespaces);

    if (preg_match_all("/^use Nubos\\\\(?:{$namespaces})\\\\(?:\\w+\\\\)*(\\w+)(?: as (\\w+))?;$/m", $source, $imports, PREG_SET_ORDER) === 0) {
        return false;
    }

    $names = array_map(static fn (array $import): string => $import[2] ?? $import[1], $imports);

    return preg_match('/^(?:\w+ )*(?:class|interface|enum) \w+[^{]*\b(?:'.implode('|', $names).')\b/m', $source) === 1;
};

$packageFiles = $absentNamespaces === [] ? [] : array_merge(...array_map(
    static fn (string $directory): array => array_keys(iterator_to_array(new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$directory}/src", FilesystemIterator::SKIP_DOTS)),
        '/\.php$/',
        RegexIterator::MATCH,
        RegexIterator::USE_KEY,
    ))),
    array_filter($packageDirectories, static fn (string $directory): bool => is_dir("{$directory}/src")),
));

return [
    'parameters' => [
        'ignoreErrors' => array_map(
            static fn (string $namespace): array => [
                'message' => "#Nubos\\\\{$namespace}\\\\#",
                'reportUnmatched' => false,
            ],
            $absentNamespaces,
        ),
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
                ...array_values(array_filter($packageFiles, $declaresAbsentType)),
            ],
        ],
    ],
];
