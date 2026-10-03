<?php

declare(strict_types=1);

namespace App\Policies\Users;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\ManagedUserResolver;

class UserPolicy
{
    public function __construct(private readonly ManagedUserResolver $managedUsers) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('members.view');
    }

    public function invite(User $user): bool
    {
        return $user->hasPermission('members.invite');
    }

    public function update(User $user, User $target): bool
    {
        return !$target->is_service
            && $this->maySteerEscalatedAccount($user, $target)
            && $this->managedUsers->manages($user, $target, 'members.update');
    }

    public function updatePassword(User $user, User $target): bool
    {
        return !$target->is_service
            && !$user->is($target)
            && $this->maySteerEscalatedAccount($user, $target)
            && $this->managedUsers->manages($user, $target, 'members.password');
    }

    public function block(User $user, User $target): bool
    {
        return !$target->is_service
            && !$user->is($target)
            && $this->managedUsers->manages($user, $target, 'members.block');
    }

    public function delete(User $user, User $target): bool
    {
        return !$target->is_service
            && !$user->is($target)
            && $this->managedUsers->manages($user, $target, 'members.remove');
    }

    private function maySteerEscalatedAccount(User $user, User $target): bool
    {
        $targetIsEscalated = $target->rolesFor()->contains(
            fn (Role $role): bool => $role->authority !== null,
        );

        return !$targetIsEscalated || $user->isEscalatedAuthority();
    }
}
