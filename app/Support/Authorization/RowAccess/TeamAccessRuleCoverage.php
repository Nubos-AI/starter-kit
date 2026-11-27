<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TeamAccessRuleCoverage
{
    public function __construct(
        private readonly TeamChainProvider $chainProvider,
        private readonly TeamAccessRuleSource $ruleSource,
    ) {}

    /**
     * @param  list<string>  $teamIds
     * @return list<string>
     */
    public function governedTeamIds(array $teamIds): array
    {
        return $this->ruleColumnAlong($teamIds, 'team_id');
    }

    /**
     * @param  list<string>  $teamIds
     */
    public function governsAny(array $teamIds): bool
    {
        return $this->governedTeamIds($teamIds) !== [];
    }

    /**
     * @return list<string>
     */
    public function chainOf(Team $team): array
    {
        return $this->chainProvider->chainOf($team);
    }

    /**
     * @return list<string>
     */
    public function ancestorsOf(Team $team): array
    {
        return array_values(array_diff($this->chainOf($team), [(string) $team->getKey()]));
    }

    /**
     * @param  list<string>  $teamIds
     *
     * @throws ValidationException
     */
    public function assertMayRewriteRulesAlong(User $actor, Team $team, array $teamIds, string $field): void
    {
        if (!$this->governsAny($teamIds)) {
            return;
        }

        $this->assertMayManageRulesOf($actor, $team, $field);
    }

    /**
     * @param  iterable<Team>  $currentTeams
     *
     * @throws ValidationException
     */
    public function assertMayJoin(User $actor, Team $target, iterable $currentTeams, string $field): void
    {
        $currentChainIds = [];

        foreach ($currentTeams as $currentTeam) {
            array_push($currentChainIds, ...$this->chainOf($currentTeam));
        }

        $restrictedObjectTypeIds = $this->ruleColumnAlong(array_values(array_unique($currentChainIds)), 'object_type_id');

        if ($restrictedObjectTypeIds === []) {
            return;
        }

        $coveredObjectTypeIds = $this->ruleColumnAlong($this->chainOf($target), 'object_type_id');

        if (array_diff($restrictedObjectTypeIds, $coveredObjectTypeIds) === []) {
            return;
        }

        $this->assertMayManageRulesOf($actor, $target, $field);
    }

    /**
     * @throws ValidationException
     */
    private function assertMayManageRulesOf(User $actor, Team $team, string $field): void
    {
        if (Gate::forUser($actor)->allows('manageAccessRules', $team)) {
            return;
        }

        Log::info('A team change was refused because it would alter access rules the actor may not manage.', [
            'tenant_id' => $team->tenant_id,
            'team_id' => (string) $team->getKey(),
            'actor_id' => (string) $actor->getKey(),
            'field' => $field,
        ]);

        throw ValidationException::withMessages([
            $field => __('i18n.backend.support.authorization.row_access.team_access_rule_coverage.this_change_would_alter_access_rules_you_may_not_manage'),
        ]);
    }

    /**
     * @param  list<string>  $teamIds
     * @return list<string>
     */
    private function ruleColumnAlong(array $teamIds, string $column): array
    {
        if ($teamIds === []) {
            return [];
        }

        return array_values(array_unique($this->ruleSource->activeFor($teamIds)
            ->map(static fn (TeamRecordAccessRule $rule): string => (string) $rule->getAttribute($column))
            ->all()));
    }
}
