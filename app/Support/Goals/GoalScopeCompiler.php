<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Enums\Goals\GoalScopeType;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\FieldDefinition;
use App\Models\Goal;
use App\Models\Team;
use App\Support\CustomFields\FieldTypeRegistry;
use Carbon\CarbonImmutable;

class GoalScopeCompiler
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly GoalTargetTeamResolver $targetTeams,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}|null  $periodBounds
     * @return array<string, mixed>
     *
     * @throws ReportNotExecutableException
     */
    public function scopedDefinition(array $definition, Goal $goal, ?array $periodBounds): array
    {
        $original = $definition['filter_definition'] ?? [];
        $tree = is_array($original) ? $original : [];
        $restricted = false;

        if ($goal->scope_type !== GoalScopeType::Tenant) {
            $tree = $this->merge($tree, [
                'field' => (string) $goal->scope_field_key,
                'operator' => FilterOperator::In->value,
                'value' => $this->targetIds($goal),
            ]);

            $restricted = true;
        }

        $periodNode = $this->periodNode($goal, $periodBounds);

        if ($periodNode !== null) {
            $tree = $this->merge($tree, $periodNode);

            $restricted = true;
        }

        if (!$restricted) {
            return $definition;
        }

        $definition['filter_definition'] = $tree;

        return $definition;
    }

    public function supportsScopeField(FieldDefinition $field): bool
    {
        if ($field->field_type === FieldType::MultiSelect) {
            return false;
        }

        return in_array(FilterOperator::In, $this->fieldTypes->filterOperators($field), true);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}|null  $periodBounds
     * @return array<string, mixed>|null
     */
    private function periodNode(Goal $goal, ?array $periodBounds): ?array
    {
        $key = $goal->period_field_key;

        if ($key === null || $periodBounds === null) {
            return null;
        }

        $zone = (string) config('reports.timezone');

        return [
            'field' => $key,
            'operator' => FilterOperator::InRange->value,
            'value' => $periodBounds['start']->setTimezone($zone)->format('Y-m-d'),
            'valueTo' => $periodBounds['end']->setTimezone($zone)->subDay()->format('Y-m-d'),
        ];
    }

    /**
     * @return list<string>
     *
     * @throws ReportNotExecutableException
     */
    private function targetIds(Goal $goal): array
    {
        if ($goal->scope_type === GoalScopeType::User) {
            return [$goal->target_user_id ?? $this->refuse()];
        }

        $team = $this->targetTeam($goal);

        if (!$goal->includes_subteams) {
            return [(string) $team->getKey()];
        }

        return array_values(array_unique([
            (string) $team->getKey(),
            ...($team->descendant_team_ids ?? []),
        ]));
    }

    /**
     * @throws ReportNotExecutableException
     */
    private function targetTeam(Goal $goal): Team
    {
        $team = $this->targetTeams->resolve($goal);

        return $team instanceof Team ? $team : $this->refuse();
    }

    /**
     * @throws ReportNotExecutableException
     */
    private function refuse(): never
    {
        throw new ReportNotExecutableException(ReportNotExecutableReason::SourceNotVisible);
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function merge(array $tree, array $node): array
    {
        $conditions = $tree['conditions'] ?? null;

        if (!is_array($conditions)) {
            return ['combinator' => 'and', 'conditions' => [$node]];
        }

        if (($tree['combinator'] ?? null) !== 'and') {
            return ['combinator' => 'and', 'conditions' => [$tree, $node]];
        }

        $tree['conditions'] = [...array_values($conditions), $node];

        return $tree;
    }
}
