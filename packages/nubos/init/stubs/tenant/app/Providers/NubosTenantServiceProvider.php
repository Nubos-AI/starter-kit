<?php

declare(strict_types=1);

namespace App\Providers;

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
        $router->prependMiddlewareToGroup('web', IdentifyTenant::class);

        Route::middleware(['web', 'auth', 'verified'])
            ->group(base_path('routes/tenant.php'));
    }
}
