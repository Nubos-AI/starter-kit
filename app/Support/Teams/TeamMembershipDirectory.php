<?php

declare(strict_types=1);

namespace App\Support\Teams;

use App\Models\Team;
use App\Models\User;
use App\Support\Http\IdentifierList;
use Illuminate\Database\Eloquent\Collection;

class TeamMembershipDirectory
{
    /**
     * @return list<string>
     */
    public function memberIdsOf(Team $team): array
    {
        return IdentifierList::from($team->users()->pluck('users.id')->all());
    }

    /**
     * @return list<string>
     */
    public function teamIdsOf(User $user): array
    {
        return IdentifierList::from($user->teams()->pluck('teams.id')->all());
    }

    /**
     * @param  list<string>  $teamIds
     */
    public function isWithinSubtree(Team $team, array $teamIds): bool
    {
        $subtreeIds = [(string) $team->getKey(), ...($team->descendant_team_ids ?? [])];

        return array_intersect($subtreeIds, $teamIds) !== [];
    }

    /**
     * @param  list<string>  $teamIds
     * @return list<string>
     */
    public function livingTeamIdsOfTenant(string $tenantId, array $teamIds): array
    {
        return IdentifierList::from($this->livingTeamsOfTenant($tenantId, $teamIds)->modelKeys());
    }

    /**
     * @param  list<string>  $teamIds
     * @return Collection<int, Team>
     */
    public function livingTeamsOfTenant(string $tenantId, array $teamIds): Collection
    {
        if ($teamIds === []) {
            return new Collection;
        }

        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereKey($teamIds)
            ->get();
    }
}
