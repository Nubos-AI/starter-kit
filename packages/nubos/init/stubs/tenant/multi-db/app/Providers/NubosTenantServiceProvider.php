<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\TenantMigrateCommand;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NubosTenantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('identify-tenant', IdentifyTenant::class);
        $router->aliasMiddleware('ensure-tenant-membership', EnsureTenantMembership::class);

        Route::middleware(['web', 'auth', 'verified'])
            ->group(base_path('routes/tenant.php'));

        if (empty(config('nubos.tenant_substructure'))) {
            $appRoutes = base_path('routes/app.php');
            if (file_exists($appRoutes)) {
                Route::domain('{tenant}.' . config('app.domain'))
                    ->middleware(['web', 'identify-tenant', 'auth', 'verified', 'ensure-tenant-membership'])
                    ->group($appRoutes);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                TenantMigrateCommand::class,
            ]);
        }
    }
}
