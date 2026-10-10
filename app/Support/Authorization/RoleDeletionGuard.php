<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Exceptions\Authorization\RoleInUseException;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;

class RoleDeletionGuard
{
    public function assertRoleHasNoAssignedUsers(Role $role): void
    {
        $userCount = $this->assignedUserCount($role);

        if ($userCount > 0) {
            throw RoleInUseException::hasAssignedUsers($userCount);
        }
    }

    public function assignedUserCount(Role $role): int
    {
        return RoleAssignment::query()
            ->where('role_id', $role->getKey())
            ->where('model_type', (new User)->getMorphClass())
            ->distinct()
            ->count('model_id');
    }
}
