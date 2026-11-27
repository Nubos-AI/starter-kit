<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Exceptions\Authorization\EscalatedTeamRoleException;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\RoleInputRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SyncTeamRolesAction
{
    public function __construct(
        private readonly RoleInputRules $rules,
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly AuthorizationDirectory $directory,
    ) {}

    /**
     * @param  list<string>  $roleIds
     *
     * @throws EscalatedTeamRoleException
     * @throws Throwable
     */
    public function execute(User $actingUser, Team $team, array $roleIds): void
    {
        Validator::make(['role_ids' => $roleIds], $this->rules->roleIds('present'))->validate();

        $roles = $this->directory->rolesByIds($roleIds);

        foreach ($roles as $role) {
            if ($role->authority !== null) {
                throw EscalatedTeamRoleException::forRole($role->name);
            }
        }

        $held = $this->directory->roleIdsAssignedTo($team);

        foreach ($roles->reject(fn (Role $role): bool => in_array($role->getKey(), $held, true)) as $role) {
            Gate::forUser($actingUser)->authorize('assign', $role);
            $this->subsetGuard->assertMayAssignRole($role);
        }

        DB::transaction(function () use ($team, $roles): void {
            $keep = $roles->pluck('id')->all();

            $team->roleAssignments()
                ->whereNotIn('role_id', $keep === [] ? [''] : $keep)
                ->delete();

            foreach ($roles as $role) {
                $team->assignRole($role);
            }
        });

        $team->forgetResolvedRoles();
    }
}
