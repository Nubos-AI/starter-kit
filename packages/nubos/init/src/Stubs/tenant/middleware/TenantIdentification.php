<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantIdentification
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $subdomain = explode('.', $host)[0] ?? null;

        if (!$subdomain) {
            abort(404);
        }

        $domain = Domain::query()
            ->where('domain', $subdomain)
            ->first();

        if (!$domain) {
            $tenant = Tenant::query()
                ->where('slug', $subdomain)
                ->first();
        } else {
            $tenant = $domain->tenant;
        }

        if (!$tenant) {
            abort(404);
        }

        if ($request->user() && !$request->user()->belongsToTenant($tenant)) {
            abort(403);
        }

        app()->instance('current_tenant', $tenant);
        $request->attributes->set('current_tenant', $tenant);

        if (config('nubos.database_strategy') === 'multi') {
            $tenant->configureDatabaseConnection();
        }

        return $next($request);
    }
}
