<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\TenantIdentification;
use Illuminate\Support\ServiceProvider;

class NubosTenantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('tenant', TenantIdentification::class);

        $this->loadRoutesFrom(base_path('routes/tenant.php'));
    }
}
