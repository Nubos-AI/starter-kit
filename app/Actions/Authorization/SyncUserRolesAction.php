<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Enums\Authorization\RoleScope;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\AssignmentScopeResolver;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\RoleInputRules;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncUserRolesAction
{
    private string $teamRoleAbility = 'members.update';

    public function __construct(
        private readonly AssignRoleAction $assignRole,
        private readonly AssignmentScopeResolver $scopeResolver,
        private readonly SelfLockoutGuard $guard,
        private readonly RoleInputRules $rules,
        private readonly ManagedUserResolver $managedUsers,
    ) {}

    /**
     * @param  list<string>  $roleIds
     * @param  list<array{role_id: string, team_id: string}>|null  $teamRoles
     *
     * @throws Throwable
     */
    public function execute(User $target, array $roleIds, ?array $teamRoles = null): void
    {
        if ($teamRoles !== null) {
            Validator::make(['team_roles' => $teamRoles], $this->rules->teamRoles('present'))->validate();
        }

        DB::transaction(function () use ($target, $roleIds, $teamRoles): void {
            $teamMorph = (new Team)->getMorphClass();

            [$teamScoped, $direct] = $target->roleAssignments()
                ->get()
                ->partition(static fn (RoleAssignment $assignment): bool => $assignment->scope_type === $teamMorph);

            $changed = $this->syncDirectRoles($target, $direct, $roleIds);

            if ($teamRoles !== null) {
                $changed = $this->syncTeamRoles($target, $teamScoped, $teamRoles) || $changed;
            }

            $actingUser = Auth::user();

            if ($actingUser instanceof User && $actingUser->is($target) && $changed) {
                $this->guard->assertActingUserRetainsRoleManagement($actingUser);
            }
        });
    }

    /**
     * @param  EloquentCollection<int, RoleAssignment>  $current
     * @param  list<string>  $roleIds
     *
     * @throws Throwable
     */
    private function syncDirectRoles(User $target, EloquentCollection $current, array $roleIds): bool
    {
        $added = $this->roles(array_values(array_diff($roleIds, $current->pluck('role_id')->all())));
        $removed = $current->reject(static fn (RoleAssignment $assignment): bool => in_array($assignment->role_id, $roleIds, true));

        foreach ($added as $role) {
            $this->assignRole->assign($target, $role, $this->scopeResolver->resolve($role));
        }

        $this->removeAll($target, $removed);

        return $added->isNotEmpty() || $removed->isNotEmpty();
    }

    /**
     * @param  EloquentCollection<int, RoleAssignment>  $current
     * @param  list<array{role_id: string, team_id: string}>  $teamRoles
     *
     * @throws Throwable
     */
    private function syncTeamRoles(User $target, EloquentCollection $current, array $teamRoles): bool
    {
        $wanted = collect($teamRoles)->keyBy(static fn (array $entry): string => "{$entry['role_id']}|{$entry['team_id']}");
        $held = $current->keyBy(static fn (RoleAssignment $assignment): string => "{$assignment->role_id}|{$assignment->scope_id}");

        $added = $wanted->diffKeys($held);
        $removed = $held->diffKeys($wanted);

        $roles = $this->roles(array_values(array_unique([
            ...$added->pluck('role_id')->all(),
            ...$removed->pluck('role_id')->all(),
        ])))->keyBy(static fn (Role $role): string => (string) $role->getKey());

        foreach ($added as $entry) {
            $role = $roles->get($entry['role_id']);

            if (!$role instanceof Role || $role->scope !== RoleScope::Team) {
                throw ValidationException::withMessages([
                    'team_roles' => __('i18n.backend.actions.authorization.sync_user_roles_action.only_team_scoped_roles_can_be_assigned_for_a_team'),
                ]);
            }

            $this->authorizeTeamRole($role, $entry['team_id']);
            $this->assignRole->assign($target, $role, $this->scopeResolver->resolve($role, $entry['team_id']));
        }

        foreach ($removed as $assignment) {
            $role = $roles->get($assignment->role_id);

            if ($role instanceof Role) {
                $this->authorizeTeamRole($role, (string) $assignment->scope_id);
            }
        }

        $this->removeAll($target, $removed->values());

        return $added->isNotEmpty() || $removed->isNotEmpty();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeTeamRole(Role $role, string $teamId): void
    {
        $actingUser = Auth::user();

        if (!$actingUser instanceof User) {
            return;
        }

        Gate::forUser($actingUser)->authorize('assign', $role);

        $reach = $this->managedUsers->reachOf($actingUser, $this->teamRoleAbility);

        if (!$reach['tenant'] && !in_array($teamId, $reach['teams'], true)) {
            throw new AuthorizationException(__('i18n.backend.support.authorization.managed_access_guard.this_team_is_outside_your_management_reach'));
        }
    }

    /**
     * @param  Collection<int, RoleAssignment>  $assignments
     *
     * @throws Throwable
     */
    private function removeAll(User $target, Collection $assignments): void
    {
        if ($assignments->isEmpty()) {
            return;
        }

        $roles = $this->roles(array_values(array_unique($assignments->pluck('role_id')->all())))
            ->keyBy(static fn (Role $role): string => (string) $role->getKey());

        foreach ($assignments as $assignment) {
            $role = $roles->get($assignment->role_id);

            if ($role instanceof Role) {
                $this->assignRole->removeAssignment($target, $assignment, $role, checkActingUser: false);
            }
        }
    }

    /**
     * @param  list<string>  $roleIds
     * @return Collection<int, Role>
     */
    private function roles(array $roleIds): Collection
    {
        if ($roleIds === []) {
            return collect();
        }

        return Role::query()->whereKey($roleIds)->get();
    }
}
