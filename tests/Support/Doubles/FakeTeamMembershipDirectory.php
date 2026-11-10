<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\Team;
use App\Models\User;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Database\Eloquent\Collection;

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
     * @var list<Team>
     */
    private array $teamModels = [];

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

    public function withTeamModels(Team ...$teams): self
    {
        $this->teamModels = array_values($teams);

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

    /**
     * @param  list<string>  $teamIds
     * @return Collection<int, Team>
     */
    public function livingTeamsOfTenant(string $tenantId, array $teamIds): Collection
    {
        return new Collection(array_values(array_filter(
            $this->teamModels,
            static fn (Team $team): bool => in_array((string) $team->getKey(), $teamIds, true),
        )));
    }
}
