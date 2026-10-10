<?php

declare(strict_types=1);

namespace App\Traits\Tenancy;

use App\Jobs\Middleware\RebindTenantContext;
use App\Models\Tenant;

trait TenantAware
{
    public ?string $tenantId = null;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RebindTenantContext];
    }

    protected function captureTenant(): void
    {
        $tenant = app('current_tenant');

        if ($tenant instanceof Tenant) {
            $this->tenantId = (string) $tenant->getKey();
        }
    }
}
