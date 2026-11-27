<?php

declare(strict_types=1);

namespace Tests\Support;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;

class ModuleFreePackageManifest extends PackageManifest
{
    private static bool $isBuilt = false;

    private string $moduleManifestFile = 'module.json';

    public static function install(Application $app): void
    {
        $manifest = new self(new Filesystem, $app->basePath(), $app->getCachedPackagesPath());

        if (!self::$isBuilt) {
            $manifest->build();
            self::$isBuilt = true;
        }

        $app->instance(PackageManifest::class, $manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function write(array $manifest): void
    {
        parent::write(array_filter(
            $manifest,
            fn (string $package): bool => !$this->isModule($package),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    private function isModule(string $package): bool
    {
        if (!InstalledVersions::isInstalled($package)) {
            return false;
        }

        $root = InstalledVersions::getInstallPath($package);

        return $root !== null && is_file("{$root}/{$this->moduleManifestFile}");
    }
}
