<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\Models\Tenant;

class AllowTenantDeletion implements TenantDeletionGuardInterface
{
    public function assertDeletable(Tenant $tenant): void {}
}
