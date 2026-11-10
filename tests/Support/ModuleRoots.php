<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

class ModuleRoots
{
    private static string $developmentLocation = 'packages/nubos';

    public static function location(): string
    {
        return dirname(__DIR__, 2).'/'.self::$developmentLocation;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $modules = [];

        foreach (glob(self::location().'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $modules[basename($path)] = $path;
        }

        ksort($modules);

        return $modules;
    }

    /**
     * @return list<string>
     */
    public static function directories(string $relative): array
    {
        return array_values(array_filter(
            array_map(static fn (string $root): string => "{$root}/{$relative}", self::all()),
            is_dir(...),
        ));
    }

    /**
     * @return list<string>
     */
    public static function files(string $pattern): array
    {
        $files = [];

        foreach (self::all() as $root) {
            $files = [...$files, ...(glob("{$root}/{$pattern}") ?: [])];
        }

        return $files;
    }

    public static function path(string $module, string $relative = ''): string
    {
        $root = self::all()[$module] ?? throw new RuntimeException("Module [{$module}] is not developed in this repository.");
        $projectRelativeRoot = substr($root, strlen(dirname(__DIR__, 2)) + 1);

        return $relative === '' ? $projectRelativeRoot : "{$projectRelativeRoot}/{$relative}";
    }

    public static function relativePath(string $path): string
    {
        foreach (self::all() as $module => $root) {
            if (str_starts_with($path, "{$root}/")) {
                return $module.'/'.substr($path, strlen($root) + 1);
            }
        }

        return $path;
    }
}
