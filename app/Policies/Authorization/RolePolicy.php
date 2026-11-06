<?php

declare(strict_types=1);

namespace App\Policies\Authorization;

use App\Enums\Authorization\RoleAuthority;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->maySteerSuperAdminRole($user, $role)
            && $this->maySteerSystemRole($user, $role, 'roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->maySteerSuperAdminRole($user, $role)
            && $this->maySteerSystemRole($user, $role, 'roles.delete');
    }

    public function assign(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.update')
            && $this->maySteerSuperAdminRole($user, $role);
    }

    private function maySteerSystemRole(User $user, Role $role, string $ability): bool
    {
        if ($user->isEscalatedAuthority()) {
            return true;
        }

        return !$role->is_system && $user->hasPermission($ability);
    }

    private function maySteerSuperAdminRole(User $user, Role $role): bool
    {
        return $role->authority !== RoleAuthority::SuperAdmin
            || $user->hasRoleWithAuthority(RoleAuthority::SuperAdmin);
    }
}
