<?php

declare(strict_types=1);

namespace App\Policies\Tenancy;

use App\Models\User;

class TenantSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->hasPermission('organisation.view');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('organisation.update');
    }
}
