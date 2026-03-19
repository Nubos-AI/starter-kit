<?php

declare(strict_types=1);

namespace App\Events\Tenants;

use App\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

class TenantDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
    ) {
    }
}
