<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery\MockInterface;
use Tests\Support\ModuleFreePackageManifest;
use Tests\Support\TestApplicationCache;
use Tests\Support\WithInstalledModules;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $usesInstalledModules = isset($this->traitsUsedByTest[WithInstalledModules::class]);

        TestApplicationCache::configure($usesInstalledModules);

        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        if (!$usesInstalledModules) {
            ModuleFreePackageManifest::install($app);
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function disconnectDatabaseConnections(): void
    {
        if ($this->app?->resolved('db') && $this->app->make('db') instanceof MockInterface) {
            return;
        }

        parent::disconnectDatabaseConnections();
    }
}
