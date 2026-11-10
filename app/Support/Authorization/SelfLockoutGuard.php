<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\RoleAuthority;
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

        $survivors = $this->assignmentsOfAuthority($this->directory->lockedAssignments(), $role->authority)->reject(
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

        $survivors = $this->assignmentsOfAuthority($this->directory->lockedAssignments(), $role->authority)->reject(
            fn (RoleAssignment $assignment): bool => $assignment->role_id === $role->getKey(),
        );

        if ($survivors->isEmpty()) {
            throw SelfLockoutException::lastEscalatedAssignmentProtected();
        }
    }

    public function assertUserIsNotLastEscalatedHolder(User $user): void
    {
        $assignments = $this->directory->lockedAssignments();

        $held = $assignments->filter(
            fn (RoleAssignment $assignment): bool => $this->belongsTo($assignment, $user),
        );

        foreach ($held as $assignment) {
            $authority = $this->authorityOf($assignment);

            if ($authority === null) {
                continue;
            }

            $survivors = $this->assignmentsOfAuthority($assignments, $authority)->reject(
                fn (RoleAssignment $candidate): bool => $this->belongsTo($candidate, $user),
            );

            if ($survivors->isEmpty()) {
                throw SelfLockoutException::lastEscalatedHolderCannotBeDeleted();
            }
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
    public function lastEscalatedHolderKeys(): Collection
    {
        return $this->directory->assignments()
            ->groupBy(fn (RoleAssignment $assignment): string => $this->authorityOf($assignment)->value ?? '')
            ->map(static fn (Collection $group): Collection => $group
                ->map(static fn (RoleAssignment $assignment): string => (string) $assignment->model_id)
                ->unique()
                ->values())
            ->filter(static fn (Collection $holders): bool => $holders->count() === 1)
            ->flatten()
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, RoleAssignment>  $assignments
     * @return Collection<int, RoleAssignment>
     */
    private function assignmentsOfAuthority(Collection $assignments, RoleAuthority $authority): Collection
    {
        return $assignments->filter(
            fn (RoleAssignment $assignment): bool => $this->authorityOf($assignment) === $authority,
        );
    }

    private function authorityOf(RoleAssignment $assignment): ?RoleAuthority
    {
        return $assignment->role?->authority;
    }

    private function belongsTo(RoleAssignment $assignment, User $user): bool
    {
        return $assignment->model_type === $user->getMorphClass()
            && $assignment->model_id === $user->getKey();
    }
}
