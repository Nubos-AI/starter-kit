<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private readonly Pipeline $pipeline) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->tenant_id !== null) {
            $tenant = $user->tenant;

            if ($tenant === null) {
                abort(403);
            }

            app()->instance('current_tenant', $tenant);
            Context::addHidden('tenant_id', $tenant->getKey());
        }

        return $this->pipeline->send($request)->through(config('modules.middleware.tenant', []))->then($next);
    }
}
