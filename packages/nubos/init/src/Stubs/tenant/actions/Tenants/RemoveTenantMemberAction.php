<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Events\Tenants\TenantMemberRemoved;
use App\Models\Tenant;
use App\Models\User;

class RemoveTenantMemberAction
{
    public function execute(Tenant $tenant, User $user): void
    {
        $tenant->users()->detach($user->id);

        event(new TenantMemberRemoved($tenant, $user));
    }
}
