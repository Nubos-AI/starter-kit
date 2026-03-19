<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->bound('currentTenant')) {
            abort(403);
        }

        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (!$user->belongsToTenant(app('currentTenant'))) {
            abort(403);
        }

        return $next($request);
    }
}
