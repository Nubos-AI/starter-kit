<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Modules\ModuleCatalog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;

class FixtureModuleCatalog extends ModuleCatalog
{
    public function __construct(?string $packagesManifest = null)
    {
        $files = new Filesystem;

        parent::__construct(
            new PackageManifest($files, base_path(), $packagesManifest ?? base_path('tests/Fixtures/Modules/packages.php')),
            $files,
        );
    }

    public function version(string $module): ?string
    {
        return $this->has($module) ? '1.0.0' : null;
    }

    protected function installPath(string $package): ?string
    {
        $path = base_path("tests/Fixtures/Modules/{$package}");

        return is_dir($path) ? $path : null;
    }
}
