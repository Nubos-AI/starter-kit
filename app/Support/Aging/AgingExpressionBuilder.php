<?php

declare(strict_types=1);

namespace App\Support\Aging;

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\AgingClock;
use App\Models\AgingRule;
use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\RecordFilterCompiler;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AgingExpressionBuilder
{
    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly RecordFilterCompiler $filterCompiler,
    ) {}

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    public function ageExpression(AgingRule $rule, Collection $fields): string
    {
        $clock = $this->clockExpression($rule, $fields);

        return "(EXTRACT(EPOCH FROM (now() - {$clock})) / 86400)";
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array{sql: literal-string, bindings: list<mixed>}
     *
     * @throws InvalidArgumentException
     */
    public function stageExpression(AgingRule $rule, Collection $fields): array
    {
        return $this->guarded($rule, $fields, $this->thresholdChain($rule, $fields, 'stage', 'int'));
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array{sql: literal-string, bindings: list<mixed>}
     *
     * @throws InvalidArgumentException
     */
    public function colorExpression(AgingRule $rule, Collection $fields): array
    {
        return $this->guarded($rule, $fields, $this->thresholdChain($rule, $fields, 'color', 'text'));
    }

    /**
     * @param  Collection<int, AgingRule>  $rules
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     *
     * @throws InvalidArgumentException
     */
    public function highestAgeExpression(Collection $rules, Collection $fields): ?array
    {
        /** @var list<array{sql: literal-string, bindings: list<mixed>}> $parts */
        $parts = [];

        foreach ($rules as $rule) {
            $parts[] = $this->guardedAgeExpression($rule, $fields);
        }

        return $this->greatest($parts);
    }

    /**
     * @param  Collection<int, AgingRule>  $rules
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     *
     * @throws InvalidArgumentException
     */
    public function highestStageExpression(Collection $rules, Collection $fields): ?array
    {
        /** @var list<array{sql: literal-string, bindings: list<mixed>}> $parts */
        $parts = [];

        foreach ($rules as $rule) {
            $parts[] = $this->stageExpression($rule, $fields);
        }

        return $this->greatest($parts);
    }

    /**
     * @return list<array{after_days: int, stage: int, color: string}>
     */
    public function rankedThresholds(AgingRule $rule): array
    {
        /** @var list<array{after_days: int, stage: int, color: string}> $ranked */
        $ranked = [];

        $ascending = (new Collection($rule->thresholds))
            ->sortBy(fn (array $threshold): int => $threshold['after_days'])
            ->values();

        foreach ($ascending as $rank => $threshold) {
            $ranked[] = [
                'after_days' => $threshold['after_days'],
                'stage' => $rank + 1,
                'color' => $threshold['color'],
            ];
        }

        return $ranked;
    }

    /** @param literal-string $table
     * @return literal-string
     */
    private function moduleClockExpression(AgingRule $rule, string $table): string
    {
        $column = config('modules.aging.clocks.'.$rule->clock->value.'.column');
        if (!is_string($column)) {
            throw new InvalidArgumentException(__('i18n.backend.support.aging.aging_expression_builder.the_clock_provider_is_not_installed'));
        }
        $column = $this->indexRegistry->safeKey($column);

        return "({$table}.{$column} AT TIME ZONE 'UTC')";
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    private function clockExpression(AgingRule $rule, Collection $fields): string
    {
        $table = $this->indexRegistry->safeKey((string) config('engine.records_table'));

        return match ($rule->clock) {
            AgingClock::UpdatedAt => "({$table}.updated_at AT TIME ZONE 'UTC')",
            default => $this->moduleClockExpression($rule, $table),
            AgingClock::Field => $this->fieldClockExpression($rule, $fields),
        };
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    private function fieldClockExpression(AgingRule $rule, Collection $fields): string
    {
        return $this->indexRegistry->timestampExpression($this->safeClockFieldKey($rule, $fields));
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    private function safeClockFieldKey(AgingRule $rule, Collection $fields): string
    {
        $key = (string) $rule->clock_field_key;
        $safeKey = $this->indexRegistry->safeKey($key);
        $field = $fields->firstWhere('key', $key);

        if (!$field instanceof FieldDefinition) {
            throw new InvalidArgumentException(
                "Aging rule \"{$rule->id}\" measures field \"{$key}\", which is not part of the given field set.",
            );
        }

        if ($field->field_type !== FieldType::Date && $field->field_type !== FieldType::DateTime) {
            throw new InvalidArgumentException(
                "Aging rule \"{$rule->id}\" measures field \"{$key}\", which holds no date.",
            );
        }

        if ($field->is_encrypted || $field->is_translatable) {
            throw new InvalidArgumentException(
                "Aging rule \"{$rule->id}\" measures field \"{$key}\", which is encrypted or translatable.",
            );
        }

        return $safeKey;
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @param  'stage'|'color'  $outcome
     * @param  'int'|'text'  $cast
     * @return array{sql: literal-string, bindings: list<mixed>}
     *
     * @throws InvalidArgumentException
     */
    private function thresholdChain(AgingRule $rule, Collection $fields, string $outcome, string $cast): array
    {
        $age = $this->ageExpression($rule, $fields);

        /** @var list<literal-string> $whens */
        $whens = [];

        /** @var list<mixed> $bindings */
        $bindings = [];

        foreach (array_reverse($this->rankedThresholds($rule)) as $threshold) {
            $whens[] = "WHEN {$age} >= ?::numeric THEN ?::{$cast}";
            $bindings[] = $threshold['after_days'];
            $bindings[] = $threshold[$outcome];
        }

        if ($whens === []) {
            return ['sql' => 'NULL', 'bindings' => []];
        }

        return ['sql' => '(CASE '.implode(' ', $whens).' END)', 'bindings' => $bindings];
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array{sql: literal-string, bindings: list<mixed>}
     *
     * @throws InvalidArgumentException
     */
    private function guardedAgeExpression(AgingRule $rule, Collection $fields): array
    {
        return $this->guarded($rule, $fields, [
            'sql' => $this->ageExpression($rule, $fields),
            'bindings' => [],
        ]);
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @param  array{sql: literal-string, bindings: list<mixed>}  $chain
     * @return array{sql: literal-string, bindings: list<mixed>}
     *
     * @throws InvalidArgumentException
     */
    private function guarded(AgingRule $rule, Collection $fields, array $chain): array
    {
        $guard = $this->conditionFragment($rule, $fields);

        if ($guard === null) {
            return $chain;
        }

        return [
            'sql' => "(CASE WHEN {$guard['sql']} THEN {$chain['sql']} END)",
            'bindings' => [...$guard['bindings'], ...$chain['bindings']],
        ];
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     *
     * @throws InvalidArgumentException
     */
    private function conditionFragment(AgingRule $rule, Collection $fields): ?array
    {
        $condition = $rule->condition;

        if (!is_array($condition)) {
            return null;
        }

        $declared = is_array($condition['conditions'] ?? null) ? $condition['conditions'] : [];

        if ($declared === []) {
            return null;
        }

        $this->assertEveryNodeTranslates($rule, $condition, $fields);

        $fragment = $this->filterCompiler->validatedTreeFragment($fields, $condition);

        if ($fragment === null) {
            throw new InvalidArgumentException(
                "Aging rule \"{$rule->id}\" carries a condition that compiles to no predicate; refusing to age every record instead.",
            );
        }

        return $fragment;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  Collection<int, FieldDefinition>  $fields
     *
     * @throws InvalidArgumentException
     */
    private function assertEveryNodeTranslates(AgingRule $rule, array $node, Collection $fields): void
    {
        $children = $node['conditions'] ?? null;

        if (is_array($children)) {
            foreach ($children as $child) {
                if (!is_array($child)) {
                    throw $this->untranslatedNode($rule, null);
                }

                $this->assertEveryNodeTranslates($rule, $child, $fields);
            }

            return;
        }

        $translated = $this->filterCompiler->validatedTreeFragment(
            $fields,
            ['combinator' => 'and', 'conditions' => [$node]],
        );

        if ($translated === null) {
            throw $this->untranslatedNode($rule, $node['field'] ?? null);
        }
    }

    private function untranslatedNode(AgingRule $rule, mixed $key): InvalidArgumentException
    {
        $name = is_string($key) ? $key : '?';

        return new InvalidArgumentException(
            "Aging rule \"{$rule->id}\" carries a condition on field \"{$name}\" that does not translate; refusing to age records the rule never covered.",
        );
    }

    /**
     * @param  list<array{sql: literal-string, bindings: list<mixed>}>  $parts
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     */
    private function greatest(array $parts): ?array
    {
        if ($parts === []) {
            return null;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        /** @var list<literal-string> $expressions */
        $expressions = [];

        /** @var list<mixed> $bindings */
        $bindings = [];

        foreach ($parts as $part) {
            $expressions[] = $part['sql'];
            $bindings = [...$bindings, ...$part['bindings']];
        }

        return ['sql' => 'GREATEST('.implode(', ', $expressions).')', 'bindings' => $bindings];
    }
}
