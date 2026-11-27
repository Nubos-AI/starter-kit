<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\Tenant;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeferredRouteModuleCheck
{
    private string $contextKey = 'module_route_check';

    public function __construct(private readonly Container $container) {}

    public function defer(?string $routeName, ?string $controller, ?string $checkedTenantId = null): void
    {
        Context::addHidden($this->contextKey, [
            'route' => $routeName,
            'controller' => $controller,
            'checked_tenant_ids' => $checkedTenantId === null ? [] : [$checkedTenantId],
        ]);
    }

    public function verify(Tenant $tenant): void
    {
        $pending = Context::getHidden($this->contextKey);

        if (!is_array($pending)) {
            return;
        }

        $tenantId = (string) $tenant->getKey();
        $checkedTenantIds = is_array($pending['checked_tenant_ids'] ?? null) ? $pending['checked_tenant_ids'] : [];

        if (in_array($tenantId, $checkedTenantIds, true)) {
            return;
        }

        $routeName = is_string($pending['route'] ?? null) ? $pending['route'] : null;
        $controller = is_string($pending['controller'] ?? null) ? $pending['controller'] : null;

        if ($this->container->make(ModuleAccess::class)->routeAllowsTenant($routeName, $controller, $tenant)) {
            Context::addHidden($this->contextKey, [...$pending, 'checked_tenant_ids' => [...$checkedTenantIds, $tenantId]]);

            return;
        }

        Log::info('A module route was refused because the tenant its handler bound later may not use the module.', [
            'route' => $routeName,
            'tenant_id' => $tenantId,
        ]);

        throw new NotFoundHttpException;
    }
}
