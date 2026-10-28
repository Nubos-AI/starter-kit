<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Exceptions\Engine\IncompleteRollupChildSetException;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\RollupChildResolver;
use Illuminate\Database\Query\Builder as QueryBuilder;

class RollupFieldHandler extends AbstractFieldHandler
{
    public function __construct(private readonly RollupChildResolver $childResolver) {}

    public function fieldType(): FieldType
    {
        return FieldType::Rollup;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return FilterOperator::forNumber();
    }

    public function cast(mixed $value, FieldDefinition $field): mixed
    {
        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return ['nullable'];
    }

    /**
     * @throws IncompleteRollupChildSetException
     */
    public function aggregateQuery(FieldDefinition $field, CustomRecord $record): QueryBuilder
    {
        $config = is_array($field->config) ? $field->config : [];
        $aggregate = $this->aggregateOf($field);
        $sourceKey = $config['source_field_key'] ?? null;
        $sourceKey = is_string($sourceKey) ? $sourceKey : '';

        $expression = match ($aggregate) {
            'count' => 'COUNT(*) as aggregate',
            'avg' => 'AVG((custom_records.data->>?)::numeric) as aggregate',
            'min' => 'MIN((custom_records.data->>?)::numeric) as aggregate',
            'max' => 'MAX((custom_records.data->>?)::numeric) as aggregate',
            default => 'SUM((custom_records.data->>?)::numeric) as aggregate',
        };

        $bindings = $aggregate === 'count' ? [] : [$sourceKey];

        return $this->childResolver->resolve($field, $record)
            ->toBase()
            ->selectRaw($expression, $bindings);
    }

    public function emptyValue(FieldDefinition $field): ?int
    {
        return $this->aggregateOf($field) === 'count' ? 0 : null;
    }

    private function aggregateOf(FieldDefinition $field): string
    {
        $aggregate = is_array($field->config) ? ($field->config['aggregate'] ?? null) : null;

        return is_string($aggregate) ? $aggregate : 'sum';
    }
}
