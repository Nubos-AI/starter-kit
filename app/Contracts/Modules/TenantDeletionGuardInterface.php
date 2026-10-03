<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\Tenant;

interface TenantDeletionGuardInterface
{
    public function assertDeletable(Tenant $tenant): void;
}
