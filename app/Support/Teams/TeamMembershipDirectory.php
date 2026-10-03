<?php

declare(strict_types=1);

namespace App\Support\Teams;

use App\Models\Team;
use App\Models\User;
use App\Support\Http\IdentifierList;

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
     * @return list<string>
     */
    public function livingTeamIdsOfTenant(string $tenantId, array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereKey($teamIds)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }
}
