<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\ServiceProvider;

class ApiRateLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(RateLimiter::class, function (): RateLimiter {
            return new RateLimiter(Cache::store((string) config('api.rate_limit.store')));
        });
    }

    public function boot(): void
    {
        RateLimiterFacade::for('api', function (Request $request): Limit|array {
            $bearer = $request->bearerToken();
            $tenantId = $request->user()?->tenant_id;

            if ($bearer !== null && is_string($tenantId)) {
                return [
                    Limit::perMinute((int) config('api.rate_limit.perMinute'))->by('token:'.hash('sha256', $bearer)),
                    Limit::perMinute((int) config('api.rate_limit.perTenantPerMinute'))->by('tenant:'.$tenantId),
                ];
            }

            if ($bearer !== null) {
                return Limit::perMinute((int) config('api.rate_limit.perMinute'))
                    ->by('token:'.hash('sha256', $bearer));
            }

            return Limit::perMinute((int) config('api.rate_limit.anonPerMinute'))
                ->by('ip:'.(string) $request->ip());
        });
    }
}
