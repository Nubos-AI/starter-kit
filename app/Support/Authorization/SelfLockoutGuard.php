<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Exceptions\Authorization\SelfLockoutException;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

class SelfLockoutGuard
{
    public function __construct(private readonly EscalatedAssignmentDirectory $directory) {}

    public function assertLastEscalatedAssignmentSurvives(Role $role, RoleAssignment $removed): void
    {
        if ($role->authority === null) {
            return;
        }

        $survivors = $this->directory->lockedAssignments()->reject(
            fn (RoleAssignment $assignment): bool => $assignment->getKey() === $removed->getKey(),
        );

        if ($survivors->isEmpty()) {
            throw SelfLockoutException::lastEscalatedAssignmentProtected();
        }
    }

    public function assertEscalationSurvivesRoleChange(Role $role): void
    {
        if ($role->authority === null) {
            return;
        }

        $survivors = $this->directory->lockedAssignments()->reject(
            fn (RoleAssignment $assignment): bool => $assignment->role_id === $role->getKey(),
        );

        if ($survivors->isEmpty()) {
            throw SelfLockoutException::lastEscalatedAssignmentProtected();
        }
    }

    public function assertUserIsNotLastEscalatedHolder(User $user): void
    {
        $assignments = $this->directory->lockedAssignments();

        if ($assignments->isEmpty()) {
            return;
        }

        $survivors = $assignments->reject(
            fn (RoleAssignment $assignment): bool => $assignment->model_type === $user->getMorphClass()
                && $assignment->model_id === $user->getKey(),
        );

        if ($survivors->isEmpty()) {
            throw SelfLockoutException::lastEscalatedHolderCannotBeDeleted();
        }
    }

    public function assertActingUserRetainsRoleManagement(User $actingUser, string $ability = 'roles.update'): void
    {
        $actingUser->forgetResolvedRoles();

        if (!$actingUser->hasPermission($ability)) {
            throw SelfLockoutException::actingUserWouldLoseRoleManagement();
        }
    }

    /**
     * @return Collection<int, string>
     */
    public function escalatedHolderKeys(): Collection
    {
        return $this->directory->holderKeys();
    }
}
