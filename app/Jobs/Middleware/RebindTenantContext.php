<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Context;

class RebindTenantContext
{
    public function handle(object $job, Closure $next): void
    {
        $hadTenant = app()->bound('current_tenant');
        $previous = $hadTenant ? app('current_tenant') : null;

        try {
            $tenantId = $this->resolveTenantId($job);

            if ($tenantId !== null) {
                $tenant = Tenant::query()->find($tenantId);

                if ($tenant !== null) {
                    app()->instance('current_tenant', $tenant);
                }
            }

            $next($job);
        } finally {
            app()->forgetInstance('current_tenant');

            if ($previous instanceof Tenant) {
                app()->instance('current_tenant', $previous);
            }
        }
    }

    private function resolveTenantId(object $job): ?string
    {
        if (property_exists($job, 'tenantId') && $job->tenantId !== null) {
            return (string) $job->tenantId;
        }

        $fromContext = Context::getHidden('tenant_id');

        return $fromContext !== null ? (string) $fromContext : null;
    }
}
