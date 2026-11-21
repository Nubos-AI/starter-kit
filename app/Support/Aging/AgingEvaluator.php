<?php

declare(strict_types=1);

namespace App\Support\Aging;

use App\Enums\Engine\SystemFilterField;
use App\Models\AgingRule;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AgingEvaluator
{
    public static string $ruleNameAttribute = 'aging_rule_name';

    public static string $colorAttribute = 'aging_color';

    private string $ruleStageAliasPrefix = 'aging_rule_stage_';

    private string $ruleColorAliasPrefix = 'aging_rule_color_';

    /**
     * @var array<int, string>
     */
    private array $ruleNamesByIndex = [];

    public function __construct(
        private readonly AgingExpressionBuilder $expressionBuilder,
        private readonly IndexRegistry $indexRegistry,
        private readonly SystemFilterFields $systemFields,
    ) {}

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array<string, array{sql: literal-string, bindings: list<mixed>}>
     */
    public function expressions(ObjectType $objectType, Collection $fields): array
    {
        return $this->virtualFieldExpressions(
            $this->fragments($objectType, $this->activeRules($objectType), $fields),
        );
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array<string, array{sql: literal-string, bindings: list<mixed>}>
     */
    public function select(Builder $query, ObjectType $objectType, Collection $fields): array
    {
        $rules = $this->activeRules($objectType);
        $fragments = $this->fragments($objectType, $rules, $fields);

        $this->ruleNamesByIndex = [];

        if ($fragments === []) {
            return [];
        }

        foreach ($rules->values() as $index => $rule) {
            $this->ruleNamesByIndex[$index] = $rule->name;
        }

        $query->select($query->getModel()->getTable().'.*');

        foreach ($fragments as $alias => $fragment) {
            $query->selectRaw("{$fragment['sql']} as {$alias}", $fragment['bindings']);
        }

        return $this->virtualFieldExpressions($fragments);
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function fields(ObjectType $objectType): Collection
    {
        if ($this->activeRules($objectType)->isEmpty()) {
            return new Collection;
        }

        return $this->systemFields->agingFields((string) $objectType->getKey());
    }

    /**
     * @param  EloquentCollection<int, CustomRecord>  $rows
     */
    public function attachRuleNames(EloquentCollection $rows): void
    {
        $namesByIndex = $this->ruleNamesByIndex;
        $this->ruleNamesByIndex = [];

        if ($namesByIndex === [] || $rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            $attributes = $row->getAttributes();
            $index = $this->winningRuleIndex($namesByIndex, $attributes);
            $color = $index === null ? null : ($attributes[$this->ruleColorAlias($index)] ?? null);

            $row->setAttribute(self::$ruleNameAttribute, $index === null ? null : $namesByIndex[$index]);
            $row->setAttribute(self::$colorAttribute, is_string($color) ? $color : null);
        }
    }

    /**
     * @param  EloquentCollection<int, AgingRule>  $rules
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array<literal-string, array{sql: literal-string, bindings: list<mixed>}>
     */
    private function fragments(ObjectType $objectType, EloquentCollection $rules, Collection $fields): array
    {
        if ($rules->isEmpty()) {
            return [];
        }

        try {
            $age = $this->expressionBuilder->highestAgeExpression($rules, $fields);
            $stage = $this->expressionBuilder->highestStageExpression($rules, $fields);

            /** @var array<literal-string, array{sql: literal-string, bindings: list<mixed>}> $perRule */
            $perRule = [];

            foreach ($rules->values() as $index => $rule) {
                $perRule[$this->ruleStageAlias($index)] = $this->expressionBuilder->stageExpression($rule, $fields);
                $perRule[$this->ruleColorAlias($index)] = $this->expressionBuilder->colorExpression($rule, $fields);
            }
        } catch (InvalidArgumentException $exception) {
            Log::warning('Aging expressions could not be built; the record list answers without aging.', [
                'object_type_id' => (string) $objectType->getKey(),
                'rule_ids' => $rules->pluck('id')->all(),
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($age === null || $stage === null) {
            return [];
        }

        return [
            SystemFilterField::AgingAge->value => $age,
            SystemFilterField::AgingStage->value => $stage,
            ...$perRule,
        ];
    }

    /**
     * @param  array<literal-string, array{sql: literal-string, bindings: list<mixed>}>  $fragments
     * @return array<string, array{sql: literal-string, bindings: list<mixed>}>
     */
    private function virtualFieldExpressions(array $fragments): array
    {
        return array_intersect_key($fragments, array_flip([
            SystemFilterField::AgingAge->value,
            SystemFilterField::AgingStage->value,
        ]));
    }

    /**
     * @param  array<int, string>  $namesByIndex
     * @param  array<string, mixed>  $attributes
     */
    private function winningRuleIndex(array $namesByIndex, array $attributes): ?int
    {
        $winner = null;
        $highest = null;

        foreach (array_keys($namesByIndex) as $index) {
            $stage = $attributes[$this->ruleStageAlias($index)] ?? null;

            if (!is_numeric($stage)) {
                continue;
            }

            if ($highest !== null && (int) $stage <= $highest) {
                continue;
            }

            $highest = (int) $stage;
            $winner = $index;
        }

        return $winner;
    }

    /**
     * @return literal-string
     */
    private function ruleStageAlias(int $index): string
    {
        return $this->indexRegistry->safeKey($this->ruleStageAliasPrefix.$index);
    }

    /**
     * @return literal-string
     */
    private function ruleColorAlias(int $index): string
    {
        return $this->indexRegistry->safeKey($this->ruleColorAliasPrefix.$index);
    }

    /**
     * @return EloquentCollection<int, AgingRule>
     */
    private function activeRules(ObjectType $objectType): EloquentCollection
    {
        return AgingRule::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
