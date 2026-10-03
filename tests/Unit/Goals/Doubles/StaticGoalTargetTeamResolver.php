<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Models\Goal;
use App\Models\Team;
use App\Support\Goals\GoalTargetTeamResolver;

class StaticGoalTargetTeamResolver extends GoalTargetTeamResolver
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @param  array<string, Team>  $teamsById
     */
    public function __construct(private array $teamsById = []) {}

    public function with(Team $team): self
    {
        $this->teamsById[(string) $team->getKey()] = $team;

        return $this;
    }

    public function resolve(Goal $goal): ?Team
    {
        $targetTeamId = (string) $goal->target_team_id;

        $this->askedFor[] = $targetTeamId;

        return $this->teamsById[$targetTeamId] ?? null;
    }
}
