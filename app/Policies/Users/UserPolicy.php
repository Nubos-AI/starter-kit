<?php

declare(strict_types=1);

namespace App\Policies\Users;

use App\Models\User;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\PermissionSubsetGuard;

class UserPolicy
{
    public function __construct(
        private readonly ManagedUserResolver $managedUsers,
        private readonly PermissionSubsetGuard $subsetGuard,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('members.view');
    }

    public function deleteAny(User $user): bool
    {
        $reach = $this->managedUsers->reachOf($user, 'members.remove');

        return $reach['tenant'] || $reach['teams'] !== [];
    }

    public function invite(User $user): bool
    {
        return $user->hasPermission('members.invite');
    }

    public function resendInvitation(User $user, User $target): bool
    {
        return !$target->is_service
            && $this->managedUsers->manages($user, $target, 'members.invite')
            && $this->maySteerAccount($user, $target);
    }

    public function update(User $user, User $target): bool
    {
        return !$target->is_service
            && $this->managedUsers->manages($user, $target, 'members.update')
            && $this->maySteerAccount($user, $target);
    }

    public function updatePassword(User $user, User $target): bool
    {
        return !$target->is_service
            && !$user->is($target)
            && $this->managedUsers->manages($user, $target, 'members.password')
            && $this->maySteerAccount($user, $target);
    }

    public function block(User $user, User $target): bool
    {
        return !$target->is_service
            && !$user->is($target)
            && $this->managedUsers->manages($user, $target, 'members.block')
            && $this->maySteerAccount($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return !$target->is_service
            && !$user->is($target)
            && $this->managedUsers->manages($user, $target, 'members.remove')
            && $this->maySteerAccount($user, $target);
    }

    private function maySteerAccount(User $user, User $target): bool
    {
        return $user->is($target) || $this->subsetGuard->maySteerAccount($user, $target);
    }
}
