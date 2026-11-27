<?php

declare(strict_types=1);

$packageDirectories = array_map('dirname', glob(__DIR__.'/vendor/*/*/module.json') ?: []);

if ($packageDirectories === []) {
    return [];
}

$packageName = static fn (string $directory): string => basename(dirname($directory)).'/'.basename($directory);

$installedPackages = array_map($packageName, $packageDirectories);

$optionalPackageSources = array_values(array_filter(
    [
        ...glob(__DIR__.'/packages/*/*/src', GLOB_ONLYDIR) ?: [],
        ...glob(__DIR__.'/packages/*/*/database', GLOB_ONLYDIR) ?: [],
    ],
    static fn (string $directory): bool => !in_array($packageName(dirname($directory)), $installedPackages, true),
));

$developedPackages = array_map($packageName, glob(__DIR__.'/packages/*/*', GLOB_ONLYDIR) ?: []);

$moduleVendors = array_values(array_unique(array_map(static fn (string $package): string => dirname($package), $installedPackages)));

$suggestedPackages = array_merge(...array_map(
    static fn (string $directory): array => array_keys(
        json_decode((string) @file_get_contents("{$directory}/composer.json"), true)['suggest'] ?? [],
    ),
    $packageDirectories,
));

$studly = static fn (string $segment): string => str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $segment)));

$absentNamespaces = array_values(array_unique(array_map(
    static fn (string $package): string => $studly(dirname($package)).'\\'.$studly(basename($package)),
    array_filter(
        $suggestedPackages,
        static fn (string $package): bool => in_array(dirname($package), $moduleVendors, true)
            && !in_array($package, [...$installedPackages, ...$developedPackages], true),
    ),
)));

$declaresAbsentType = static function (string $file) use ($absentNamespaces): bool {
    $source = (string) file_get_contents($file);
    $namespaces = implode('|', array_map(static fn (string $namespace): string => preg_quote($namespace, '/'), $absentNamespaces));

    if (preg_match_all("/^use (?:{$namespaces})\\\\(?:\\w+\\\\)*(\\w+)(?: as (\\w+))?;$/m", $source, $imports, PREG_SET_ORDER) === 0) {
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
                'message' => '#'.preg_quote($namespace, '#').'\\\\#',
                'reportUnmatched' => false,
            ],
            $absentNamespaces,
        ),
        'tmpDir' => sys_get_temp_dir().'/phpstan/'.md5(implode(',', $installedPackages)),
        'databaseMigrationsPath' => array_values(array_filter(
            array_map(static fn (string $directory): string => "{$directory}/database/migrations", $packageDirectories),
            is_dir(...),
        )),
        'paths' => $packageDirectories,
        'scanDirectories' => $optionalPackageSources,
        'excludePaths' => [
            'analyse' => [
                ...array_merge(...array_map(
                    static fn (string $directory): array => array_map(
                        static fn (string $folder): string => "{$directory}/{$folder}/*",
                        ['config', 'lang', 'resources', 'routes', 'tests'],
                    ),
                    $packageDirectories,
                )),
                ...array_values(array_filter($packageFiles, $declaresAbsentType)),
            ],
        ],
    ],
];
