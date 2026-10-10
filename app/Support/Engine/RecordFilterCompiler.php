<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Tenant;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RecordFilterCompiler
{
    /**
     * @var list<string>
     */
    private array $stringOperators = ['contains', 'notContains', 'startsWith', 'endsWith'];

    /**
     * @var list<string>
     */
    private array $numericOperators = [
        'greaterThan', 'greaterThanOrEqual', 'lessThan', 'lessThanOrEqual', 'inRange',
    ];

    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly FilterTreeValidator $validator,
        private readonly SystemFilterFields $systemFields,
    ) {}

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<array-key, mixed>  $filterModel
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     */
    public function apply(
        Builder $query,
        Collection $allowedFields,
        array $filterModel,
        array $virtualFieldExpressions = [],
    ): void {
        foreach ($filterModel as $colId => $spec) {
            if (!is_string($colId) || !is_array($spec)) {
                continue;
            }

            $field = $allowedFields->firstWhere('key', $colId);

            if (!$field instanceof FieldDefinition) {
                continue;
            }

            $type = $spec['type'] ?? null;

            if (!is_string($type)) {
                continue;
            }

            if (!$this->isOperatorCompatible($field, $type)) {
                continue;
            }

            $expression = $this->fieldExpression($field, $virtualFieldExpressions);

            $fragment = $this->operatorFragment(
                $expression['sql'],
                $expression['bindings'],
                $type,
                $spec['filter'] ?? null,
                $spec['filterTo'] ?? null,
            );

            if ($fragment === null) {
                continue;
            }

            $query->whereRaw($fragment['sql'], $fragment['bindings'], 'and');
        }
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, mixed>  $tree
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     */
    public function applyTree(
        Builder $query,
        Collection $allowedFields,
        array $tree,
        FieldVisibilityResolver $fieldVisibility,
        ObjectType $objectType,
        array $virtualFieldExpressions = [],
    ): void {
        $this->validator->validate($tree, $allowedFields, $fieldVisibility, $objectType);

        $this->applyValidatedTree($query, $allowedFields, $tree, $virtualFieldExpressions);
    }

    /**
     * @param  Builder<covariant CustomRecord>  $query
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, mixed>  $tree
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     */
    public function applyValidatedTree(
        Builder $query,
        Collection $allowedFields,
        array $tree,
        array $virtualFieldExpressions = [],
    ): void {
        $fragment = $this->validatedTreeFragment($allowedFields, $tree, $virtualFieldExpressions);

        if ($fragment === null) {
            return;
        }

        $query->whereRaw($fragment['sql'], $fragment['bindings'], 'and');
    }

    /**
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, mixed>  $tree
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     */
    public function validatedTreeFragment(
        Collection $allowedFields,
        array $tree,
        array $virtualFieldExpressions = [],
    ): ?array {
        if (!array_key_exists('conditions', $tree)) {
            return null;
        }

        return $this->groupFragment($tree, $allowedFields, $virtualFieldExpressions);
    }

    /**
     * @return literal-string
     */
    public function columnExpression(FieldDefinition $field): string
    {
        if ($this->systemFields->isSystemField($field)) {
            return (string) $this->systemFields->columnFor($field->key);
        }

        if ($field->is_translatable) {
            $fallback = config('app.fallback_locale');

            return $this->indexRegistry->localeSortExpression(
                $field->key,
                app()->getLocale(),
                is_string($fallback) ? $fallback : null,
            );
        }

        return $this->indexRegistry->sortExpression($field);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     */
    private function groupFragment(array $group, Collection $allowedFields, array $virtualFieldExpressions): ?array
    {
        $combinator = ($group['combinator'] ?? 'and') === 'or' ? 'OR' : 'AND';
        $conditions = is_array($group['conditions'] ?? null) ? $group['conditions'] : [];

        /** @var list<literal-string> $parts */
        $parts = [];

        /** @var list<mixed> $bindings */
        $bindings = [];

        foreach ($conditions as $child) {
            if (!is_array($child)) {
                continue;
            }

            $fragment = array_key_exists('conditions', $child)
                ? $this->groupFragment($child, $allowedFields, $virtualFieldExpressions)
                : $this->conditionFragment($child, $allowedFields, $virtualFieldExpressions);

            if ($fragment === null) {
                continue;
            }

            $parts[] = $fragment['sql'];
            $bindings = [...$bindings, ...$fragment['bindings']];
        }

        if ($parts === []) {
            return null;
        }

        return [
            'sql' => '('.implode(" {$combinator} ", $parts).')',
            'bindings' => $bindings,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $condition
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     */
    private function conditionFragment(array $condition, Collection $allowedFields, array $virtualFieldExpressions): ?array
    {
        $key = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;

        if (!is_string($key) || !is_string($operator)) {
            return null;
        }

        $field = $allowedFields->firstWhere('key', $key);

        if (!$field instanceof FieldDefinition) {
            return null;
        }

        if ($operator === 'has' || $operator === 'hasNot') {
            return $this->relationFragment($field, $operator === 'hasNot');
        }

        $expression = $this->fieldExpression($field, $virtualFieldExpressions);

        return $this->operatorFragment(
            $expression['sql'],
            $expression['bindings'],
            $operator,
            $condition['value'] ?? null,
            $condition['valueTo'] ?? null,
        );
    }

    /**
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     * @return array{sql: literal-string, bindings: list<mixed>}
     */
    private function fieldExpression(FieldDefinition $field, array $virtualFieldExpressions): array
    {
        $virtual = $virtualFieldExpressions[$field->key] ?? null;

        if ($virtual !== null) {
            return $virtual;
        }

        if ($this->systemFields->isAgingField($field->key)) {
            return ['sql' => 'NULL', 'bindings' => []];
        }

        return ['sql' => $this->columnExpression($field), 'bindings' => []];
    }

    private function isOperatorCompatible(FieldDefinition $field, string $type): bool
    {
        if ($field->usesNumericIndex()) {
            return !in_array($type, $this->stringOperators, true);
        }

        return !in_array($type, $this->numericOperators, true);
    }

    /**
     * @param  literal-string  $column
     * @param  list<mixed>  $columnBindings
     * @return array{sql: literal-string, bindings: list<mixed>}|null
     */
    private function operatorFragment(string $column, array $columnBindings, string $type, mixed $value, mixed $valueTo): ?array
    {
        return match ($type) {
            'equals' => ['sql' => "{$column} = ?", 'bindings' => [...$columnBindings, $value]],
            'notEqual' => ['sql' => "{$column} <> ?", 'bindings' => [...$columnBindings, $value]],
            'contains' => [
                'sql' => "{$column} ILIKE ?",
                'bindings' => [...$columnBindings, '%'.$this->escapeLike((string) $value).'%'],
            ],
            'notContains' => [
                'sql' => "{$column} NOT ILIKE ?",
                'bindings' => [...$columnBindings, '%'.$this->escapeLike((string) $value).'%'],
            ],
            'startsWith' => [
                'sql' => "{$column} ILIKE ?",
                'bindings' => [...$columnBindings, $this->escapeLike((string) $value).'%'],
            ],
            'endsWith' => [
                'sql' => "{$column} ILIKE ?",
                'bindings' => [...$columnBindings, '%'.$this->escapeLike((string) $value)],
            ],
            'greaterThan' => ['sql' => "{$column} > ?", 'bindings' => [...$columnBindings, $value]],
            'greaterThanOrEqual' => ['sql' => "{$column} >= ?", 'bindings' => [...$columnBindings, $value]],
            'lessThan' => ['sql' => "{$column} < ?", 'bindings' => [...$columnBindings, $value]],
            'lessThanOrEqual' => ['sql' => "{$column} <= ?", 'bindings' => [...$columnBindings, $value]],
            'inRange' => [
                'sql' => "{$column} BETWEEN ? AND ?",
                'bindings' => [...$columnBindings, $value, $valueTo],
            ],
            'in' => $this->inFragment($column, $columnBindings, $value, false),
            'notIn' => $this->inFragment($column, $columnBindings, $value, true),
            'blank' => ['sql' => "{$column} IS NULL", 'bindings' => $columnBindings],
            'notBlank' => ['sql' => "{$column} IS NOT NULL", 'bindings' => $columnBindings],
            default => null,
        };
    }

    /**
     * @param  literal-string  $column
     * @param  list<mixed>  $columnBindings
     * @return array{sql: literal-string, bindings: list<mixed>}
     */
    private function inFragment(string $column, array $columnBindings, mixed $values, bool $negate): array
    {
        if (!is_array($values) || $values === []) {
            return ['sql' => $negate ? '1 = 1' : '1 = 0', 'bindings' => []];
        }

        $values = array_values($values);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $operator = $negate ? 'NOT IN' : 'IN';

        return [
            'sql' => "{$column} {$operator} ({$placeholders})",
            'bindings' => [...$columnBindings, ...$values],
        ];
    }

    /**
     * @return array{sql: literal-string, bindings: list<mixed>}
     */
    private function relationFragment(FieldDefinition $field, bool $negate): array
    {
        $config = is_array($field->config) ? $field->config : [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;

        if (!is_string($relationshipTypeId) || $relationshipTypeId === '') {
            return [
                'sql' => $negate ? '(TRUE)' : '(FALSE)',
                'bindings' => [],
            ];
        }

        $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : null;
        $recordsTable = $this->indexRegistry->safeKey((string) config('engine.records_table'));

        /** @var list<mixed> $bindings */
        $bindings = [];

        $tenantPredicate = 'record_links.tenant_id IS NULL';

        if ($tenantId !== null) {
            $tenantPredicate = 'record_links.tenant_id = ?';
            $bindings[] = $tenantId;
        }

        $typePredicate = 'record_links.relationship_type_id = ?';
        $bindings[] = $relationshipTypeId;

        $exists = 'EXISTS (SELECT * FROM record_links'
            ." WHERE record_links.from_record_id = {$recordsTable}.id"
            ." AND {$tenantPredicate}"
            ." AND {$typePredicate})";

        return [
            'sql' => $negate ? "(NOT {$exists})" : "({$exists})",
            'bindings' => $bindings,
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
