<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Modules\ModuleCatalog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;

class FixtureModuleCatalog extends ModuleCatalog
{
    public function __construct(?string $packagesManifest = null, Filesystem $files = new Filesystem)
    {
        parent::__construct(
            new PackageManifest($files, base_path(), $packagesManifest ?? base_path('tests/Fixtures/Modules/packages.php')),
            $files,
        );
    }

    protected function installedVersion(string $module): ?string
    {
        if (!$this->has($module)) {
            return null;
        }

        $composer = base_path("tests/Fixtures/Modules/{$module}/composer.json");

        if (!is_file($composer)) {
            return 'dev-main';
        }

        /** @var array{version?: string} $package */
        $package = json_decode((string) file_get_contents($composer), true, flags: JSON_THROW_ON_ERROR);

        return $package['version'] ?? 'dev-main';
    }

    protected function installPath(string $package): ?string
    {
        $path = base_path("tests/Fixtures/Modules/{$package}");

        return is_dir($path) ? $path : null;
    }
}
