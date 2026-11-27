<?php

declare(strict_types=1);

namespace App\Traits\Users;

use App\Models\User;

trait LoadsTenantUser
{
    private function tenantUser(string $tenantId, string $userId): User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->firstOrFail();
    }
}
