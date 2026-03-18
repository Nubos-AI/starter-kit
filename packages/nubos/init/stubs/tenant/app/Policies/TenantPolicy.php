<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenants()->exists();
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->ownsTenant($tenant);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->ownsTenant($tenant);
    }
}
