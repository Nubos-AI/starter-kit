<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

class EnforceMaintenanceLock
{
    public function __construct(private readonly MaintenanceLockRegistry $registry) {}

    /**
     * @throws TenantUnderMaintenanceException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = TenantContext::currentId();

        if ($tenantId === null || $request->isMethodSafe()) {
            return $next($request);
        }

        $route = $request->route();
        $routeName = $route instanceof Route ? $route->getName() : null;

        if (in_array($routeName, MaintenanceLockRegistry::$exemptRouteNames, true)
            || in_array($routeName, MaintenanceLockRegistry::$nonWritingRouteNames, true)) {
            return $next($request);
        }

        $context = [
            'route' => $routeName,
            'method' => $request->method(),
        ];

        $this->registry->assertWritable($tenantId, 'http', $context);

        $liveTenantId = $this->liveTenantId($route);

        if ($liveTenantId !== null) {
            $this->registry->assertWritable($liveTenantId, 'http', $context);
        }

        return $next($request);
    }

    private function liveTenantId(mixed $route): ?string
    {
        if (!$route instanceof Route || !in_array('live-tenant', $route->gatherMiddleware(), true)) {
            return null;
        }

        $sourceTenantId = TenantContext::current()?->getAttribute('source_tenant_id');

        return is_string($sourceTenantId) ? $sourceTenantId : null;
    }
}
