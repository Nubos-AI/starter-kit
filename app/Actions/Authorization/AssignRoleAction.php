<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssignRoleAction
{
    public function __construct(
        private readonly SelfLockoutGuard $guard,
        private readonly AdminArtifactAuditor $auditor,
        private readonly PermissionSubsetGuard $subsetGuard,
    ) {}

    /**
     * @throws Throwable
     */
    public function assign(User $target, Role $role, ?Model $scope = null): RoleAssignment
    {
        $this->subsetGuard->assertMayAssignRole($role);

        return DB::transaction(function () use ($target, $role, $scope): RoleAssignment {
            $assignment = $target->assignRole($role, $scope);

            if ($assignment->wasRecentlyCreated) {
                $this->auditor->recordCreated($assignment);
            }

            return $assignment;
        });
    }

    /**
     * @throws Throwable
     */
    public function remove(User $target, Role $role, ?Model $scope = null, bool $checkActingUser = true): void
    {
        DB::transaction(function () use ($target, $role, $scope, $checkActingUser): void {
            $assignment = $this->findAssignment($target, $role, $scope);

            if (!$assignment instanceof RoleAssignment) {
                return;
            }

            $this->guard->assertLastEscalatedAssignmentSurvives($role, $assignment);
            $this->auditor->recordDeleted($assignment);

            $assignment->delete();

            $target->forgetResolvedRoles();

            $actingUser = $this->actingUser();

            if ($checkActingUser && $actingUser !== null && $actingUser->is($target)) {
                $this->guard->assertActingUserRetainsRoleManagement($actingUser);
            }
        });
    }

    /**
     * @throws Throwable
     */
    public function downgrade(User $target, Role $from, Role $to, ?Model $scope = null): RoleAssignment
    {
        return DB::transaction(function () use ($target, $from, $to, $scope): RoleAssignment {
            $this->remove($target, $from, $scope, checkActingUser: false);
            $assignment = $this->assign($target, $to, $scope);

            $actingUser = $this->actingUser();

            if ($actingUser !== null && $actingUser->is($target)) {
                $this->guard->assertActingUserRetainsRoleManagement($actingUser);
            }

            return $assignment;
        });
    }

    private function findAssignment(User $target, Role $role, ?Model $scope): ?RoleAssignment
    {
        $query = $target->roleAssignments()->where('role_id', $role->getKey());

        if ($scope === null) {
            $query->whereNull('scope_type')->whereNull('scope_id');
        } else {
            $query->where('scope_type', $scope->getMorphClass())
                ->where('scope_id', $scope->getKey());
        }

        return $query->first();
    }

    private function actingUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
