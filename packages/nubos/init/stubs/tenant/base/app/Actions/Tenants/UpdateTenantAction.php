<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Events\Tenants\TenantUpdated;
use App\Models\Tenant;

class UpdateTenantAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);

        $tenant = $tenant->refresh();

        TenantUpdated::dispatch($tenant);

        return $tenant;
    }
}
