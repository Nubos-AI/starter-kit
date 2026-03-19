<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $appDomain = $this->getAppDomain();

        if (!$appDomain) {
            abort(500, 'Tenant identification requires a configured app domain.');
        }

        $subdomain = $this->extractSubdomain($host, $appDomain);

        if ($subdomain === null) {
            return $this->redirectToFallback();
        }

        if ($this->isReservedSubdomain($subdomain)) {
            return $this->redirectToFallback();
        }

        $tenant = Tenant::query()->where('slug', $subdomain)->first();

        if (!$tenant) {
            abort(404);
        }

        app()->instance('currentTenant', $tenant);

        if (method_exists($tenant, 'configureDatabaseConnection')) {
            $tenant->configureDatabaseConnection();
        }

        return $next($request);
    }

    private function extractSubdomain(string $host, string $appDomain): ?string
    {
        if (!str_ends_with($host, ".{$appDomain}")) {
            return null;
        }

        $subdomain = Str::before($host, ".{$appDomain}");

        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        return $subdomain;
    }

    private function getAppDomain(): ?string
    {
        return config('app.domain')
            ?? parse_url((string) config('app.url'), PHP_URL_HOST)
            ?: null;
    }

    private function isReservedSubdomain(string $subdomain): bool
    {
        /** @var array<int, string> $reserved */
        $reserved = config('nubos.reserved_subdomains', []);

        return in_array($subdomain, $reserved, true);
    }

    private function redirectToFallback(): RedirectResponse
    {
        /** @var string $url */
        $url = config('nubos.tenant_fallback_url', '/');

        return new RedirectResponse($url);
    }
}
