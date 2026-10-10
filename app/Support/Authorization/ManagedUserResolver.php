<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;

class ManagedUserResolver
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly AuthorizationDirectory $directory,
    ) {}

    public function manages(User $actingUser, User $target, string $ability): bool
    {
        if ($actingUser->tenant_id === null || $actingUser->tenant_id !== $target->tenant_id) {
            return false;
        }

        if ($this->coversTenant($actingUser, $ability)) {
            return true;
        }

        $teamIds = $this->reachableTeamIds($actingUser, $ability);

        if ($teamIds === []) {
            return false;
        }

        return $target->teams()
            ->withoutGlobalScopes()
            ->whereKey($teamIds)
            ->exists();
    }

    /**
     * @return array{tenant: bool, teams: list<string>}
     */
    public function reachOf(User $actingUser, string $ability): array
    {
        if ($this->coversTenant($actingUser, $ability)) {
            return ['tenant' => true, 'teams' => []];
        }

        return ['tenant' => false, 'teams' => $this->reachableTeamIds($actingUser, $ability)];
    }

    private function coversTenant(User $actingUser, string $ability): bool
    {
        $tenant = $actingUser->tenant;

        return $tenant !== null
            && $this->permissionResolver->verdictFor($actingUser, $ability, $tenant) === true;
    }

    /**
     * @return list<string>
     */
    private function reachableTeamIds(User $actingUser, string $ability): array
    {
        $reachable = [];

        foreach ($this->directory->teamsScopingAssignmentsOf($actingUser) as $team) {
            if ($this->permissionResolver->verdictFor($actingUser, $ability, $team) !== true) {
                continue;
            }

            $reachable[] = (string) $team->getKey();

            if ($this->descendsInto($actingUser, $team, $ability)) {
                foreach ($team->descendant_team_ids as $descendantId) {
                    $reachable[] = $descendantId;
                }
            }
        }

        return array_values(array_unique($reachable));
    }

    private function descendsInto(User $actingUser, Team $team, string $ability): bool
    {
        return $actingUser->assignedRolesFor($team)->contains(
            fn (Role $role): bool => $role->grants_subteam_visibility && $this->carries($role, $ability),
        );
    }

    private function carries(Role $role, string $ability): bool
    {
        if ($role->authority !== null) {
            return true;
        }

        return $role->permissions->contains(
            fn (Permission $permission): bool => $permission->name === $ability,
        );
    }
}
