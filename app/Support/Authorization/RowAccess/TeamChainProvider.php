<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\CurrentTeamResolver;

class TeamChainProvider
{
    public function __construct(private readonly CurrentTeamResolver $currentTeam) {}

    /**
     * @return list<list<string>>
     */
    public function chainsFor(User $user): array
    {
        $active = $this->currentTeam->resolve($user);

        if ($active instanceof Team) {
            return [$this->chainOf($active)];
        }

        return array_values($user->teams
            ->map(fn (Team $team): array => $this->chainOf($team))
            ->all());
    }

    /**
     * @return list<string>
     */
    private function chainOf(Team $team): array
    {
        $ancestors = is_array($team->ancestor_team_ids) ? $team->ancestor_team_ids : [];

        return array_values(array_unique(array_merge(
            [(string) $team->getKey()],
            array_map(static fn (mixed $id): string => $id, $ancestors),
        )));
    }
}
