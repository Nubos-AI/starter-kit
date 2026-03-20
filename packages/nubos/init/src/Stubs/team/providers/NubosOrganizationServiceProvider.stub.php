<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\RedirectToCurrent{{Model}};
use App\Http\Middleware\SetCurrent{{Model}};
use Illuminate\Support\ServiceProvider;

class NubosOrganizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('set-current-{{model}}', SetCurrent{{Model}}::class);
        $this->app['router']->aliasMiddleware('redirect-to-current-{{model}}', RedirectToCurrent{{Model}}::class);

        $this->loadRoutesFrom(base_path('routes/{{model}}.php'));
    }
}
