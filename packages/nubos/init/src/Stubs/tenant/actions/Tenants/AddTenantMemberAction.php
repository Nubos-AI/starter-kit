<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Events\Tenants\TenantMemberAdded;
use App\Models\Tenant;
use App\Models\User;

class AddTenantMemberAction
{
    public function execute(Tenant $tenant, User $user, ?string $role = null): void
    {
        $tenant->users()->attach($user->id, ['role' => $role]);

        event(new TenantMemberAdded($tenant, $user));
    }
}
