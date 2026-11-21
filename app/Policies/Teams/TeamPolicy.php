<?php

declare(strict_types=1);

namespace App\Policies\Teams;

use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\RowAccess\TeamAccessRuleCoverage;
use App\Support\Teams\TeamMembershipDirectory;

class TeamPolicy
{
    public function __construct(
        private readonly ManagedUserResolver $managedUsers,
        private readonly TeamMembershipDirectory $memberships,
        private readonly TeamAccessRuleCoverage $ruleCoverage,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('teams.view');
    }

    public function view(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teams.create');
    }

    public function update(User $user, Team $team): bool
    {
        return $this->manages($user, $team, 'teams.update');
    }

    public function delete(User $user, Team $team): bool
    {
        if (!$this->manages($user, $team, 'teams.delete')) {
            return false;
        }

        if (!$this->mayChangeRulesOver($user, $team)) {
            return false;
        }

        return $this->mayRewriteRulesAlong($user, $team, $this->ruleCoverage->chainOf($team));
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('teams.delete');
    }

    public function reparent(User $user, Team $team): bool
    {
        if (!$this->manages($user, $team, 'teams.reparent')) {
            return false;
        }

        if (!$this->mayChangeRulesOver($user, $team)) {
            return false;
        }

        return $this->mayRewriteRulesAlong($user, $team, $this->ruleCoverage->ancestorsOf($team));
    }

    public function adopt(User $user, Team $parent): bool
    {
        return $this->manages($user, $parent, 'teams.reparent');
    }

    public function viewAccessRules(User $user, Team $team): bool
    {
        return $this->manages($user, $team, 'teams.manage');
    }

    public function manageAccessRules(User $user, Team $team): bool
    {
        if (!$this->manages($user, $team, 'teams.manage')) {
            return false;
        }

        return $this->mayChangeRulesOver($user, $team);
    }

    /**
     * @param  list<string>  $teamIds
     */
    private function mayRewriteRulesAlong(User $user, Team $team, array $teamIds): bool
    {
        return !$this->ruleCoverage->governsAny($teamIds) || $this->manages($user, $team, 'teams.manage');
    }

    private function mayChangeRulesOver(User $user, Team $team): bool
    {
        return !$this->memberships->isWithinSubtree($team, $this->memberships->teamIdsOf($user))
            || $user->isEscalatedAuthority();
    }

    private function manages(User $user, Team $team, string $ability): bool
    {
        if ($user->tenant_id === null || $user->tenant_id !== $team->tenant_id) {
            return false;
        }

        $reach = $this->managedUsers->reachOf($user, $ability);

        return $reach['tenant'] || in_array((string) $team->getKey(), $reach['teams'], true);
    }
}
