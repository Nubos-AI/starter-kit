<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\Tenant;

interface TenantProvisioningExtensionInterface
{
    public function provisioned(Tenant $tenant): void;
}
