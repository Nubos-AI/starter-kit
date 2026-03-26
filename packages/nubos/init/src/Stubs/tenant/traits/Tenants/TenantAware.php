<?php

declare(strict_types=1);

namespace App\Traits\Tenants;

use App\Models\Tenant;

trait TenantAware
{
    public string $tenantId;

    public function initializeTenantAware(): void
    {
        if (app()->bound('current_tenant')) {
            $this->tenantId = app('current_tenant')->id;
        }
    }

    public function restoreTenantContext(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenant->configureDatabaseConnection();
        app()->instance('current_tenant', $tenant);
    }
}
