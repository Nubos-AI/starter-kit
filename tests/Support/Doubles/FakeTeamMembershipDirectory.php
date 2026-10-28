<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\Team;
use App\Models\User;
use App\Support\Teams\TeamMembershipDirectory;

class FakeTeamMembershipDirectory extends TeamMembershipDirectory
{
    /**
     * @var array<string, list<string>>
     */
    private array $membersByTeam = [];

    /**
     * @var array<string, list<string>>
     */
    private array $teamsByUser = [];

    /**
     * @var list<string>
     */
    private array $livingTeamIds = [];

    /**
     * @param  list<string>  $userIds
     */
    public function withMembers(Team $team, array $userIds): self
    {
        $this->membersByTeam[(string) $team->getKey()] = $userIds;

        return $this;
    }

    /**
     * @param  list<string>  $teamIds
     */
    public function withTeams(User $user, array $teamIds): self
    {
        $this->teamsByUser[(string) $user->getKey()] = $teamIds;

        return $this;
    }

    /**
     * @param  list<string>  $teamIds
     */
    public function withLivingTeams(array $teamIds): self
    {
        $this->livingTeamIds = $teamIds;

        return $this;
    }

    public function memberIdsOf(Team $team): array
    {
        return $this->membersByTeam[(string) $team->getKey()] ?? [];
    }

    public function teamIdsOf(User $user): array
    {
        return $this->teamsByUser[(string) $user->getKey()] ?? [];
    }

    public function livingTeamIdsOfTenant(string $tenantId, array $teamIds): array
    {
        return array_values(array_intersect($teamIds, $this->livingTeamIds));
    }
}
