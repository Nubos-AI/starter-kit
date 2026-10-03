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
use Illuminate\Support\Facades\DB;

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
    public function compute(FieldDefinition $field, CustomRecord $record): int|float|null
    {
        $config = is_array($field->config) ? $field->config : [];
        $aggregate = $config['aggregate'] ?? null;
        $aggregate = is_string($aggregate) ? $aggregate : 'sum';
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

        $row = $this->childResolver->resolve($field, $record)
            ->toBase()
            ->selectRaw($expression, $bindings)
            ->first();

        $value = $row?->aggregate;

        if ($value === null) {
            return null;
        }

        return $value + 0;
    }

    public function materialize(FieldDefinition $field, CustomRecord $record): int|float|null
    {
        $value = $this->compute($field, $record);
        $tenantId = (string) $record->getAttribute('tenant_id');
        $path = '{'.$field->key.'}';

        $base = "(case when jsonb_typeof(data) = 'object' then data else '{}'::jsonb end)";

        if ($value === null) {
            DB::update(
                "UPDATE custom_records SET data = jsonb_set({$base}, ?, 'null'::jsonb, true) WHERE id = ? AND tenant_id = ?",
                [$path, (string) $record->getKey(), $tenantId],
            );
        } else {
            DB::update(
                "UPDATE custom_records SET data = jsonb_set({$base}, ?, to_jsonb(?::numeric), true) WHERE id = ? AND tenant_id = ?",
                [$path, $value, (string) $record->getKey(), $tenantId],
            );
        }

        return $value;
    }
}
