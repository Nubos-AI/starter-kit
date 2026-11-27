<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;

class TestApplicationCache
{
    private static ?string $directory = null;

    private static string $directoryPrefix = 'nubos-test-cache-';

    public static function configure(bool $withInstalledModules): void
    {
        $variant = $withInstalledModules ? 'with-modules' : 'without-modules';

        self::setEnvironment('APP_SERVICES_CACHE', self::path("services-{$variant}.php"));
        self::setEnvironment('APP_PACKAGES_CACHE', self::path("packages-{$variant}.php"));
    }

    private static function path(string $file): string
    {
        return self::directory()."/{$file}";
    }

    private static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $directory = sys_get_temp_dir().'/'.self::$directoryPrefix.getmypid();
        $files = new Filesystem;

        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);

        register_shutdown_function(fn (): bool => $files->deleteDirectory($directory));

        return self::$directory = $directory;
    }

    private static function setEnvironment(string $key, string $value): void
    {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }
}
