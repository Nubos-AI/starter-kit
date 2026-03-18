<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveFromSubdomain($request->getHost());

        if ($tenant) {
            app()->instance('currentTenant', $tenant);
        }

        return $next($request);
    }

    private function resolveFromSubdomain(string $host): ?Tenant
    {
        $appDomain = config('app.domain')
            ?? parse_url((string) config('app.url'), PHP_URL_HOST);

        if (!$appDomain || !str_ends_with($host, ".{$appDomain}")) {
            return null;
        }

        $subdomain = Str::before($host, ".{$appDomain}");

        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        return Tenant::query()->where('slug', $subdomain)->first();
    }
}
