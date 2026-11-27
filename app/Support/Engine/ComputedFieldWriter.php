<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Handlers\CustomFields\ComputedFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use JsonException;

class ComputedFieldWriter
{
    public function __construct(
        private readonly ComputedFieldOrderResolver $orderResolver,
        private readonly ComputedFieldHandler $handler,
        private readonly RecordDataPatcher $patcher,
    ) {}

    /**
     * @param  list<string>  $changedFieldKeys
     * @return list<FieldDefinition>
     */
    public function orderFor(string $objectTypeId, array $changedFieldKeys = []): array
    {
        return $this->orderResolver->orderFor($objectTypeId, $changedFieldKeys);
    }

    /**
     * @param  list<FieldDefinition>  $fields
     * @return array<string, string|bool|FormulaErrorValue>
     *
     * @throws JsonException
     */
    public function write(CustomRecord $record, array $fields): array
    {
        /** @var array<string, string|bool|FormulaErrorValue> $values */
        $values = [];

        /** @var array<string, mixed> $pendingValues */
        $pendingValues = [];

        /** @var array<string, array{sql: string, bindings: list<mixed>}> $expressions */
        $expressions = [];

        foreach ($fields as $field) {
            $value = $this->handler->compute($field, $record, $pendingValues);

            $values[$field->key] = $value;
            $pendingValues[$field->key] = $this->handler->storedValue($value, $field);
            $expressions[$field->key] = $this->handler->jsonExpression($value, $field);
        }

        $this->patcher->merge($record, $expressions);

        return $values;
    }

    /**
     * @param  list<string>  $changedFieldKeys
     * @return list<string>
     *
     * @throws JsonException
     */
    public function materialize(CustomRecord $record, array $changedFieldKeys = []): array
    {
        $fields = $this->orderFor($record->object_type_id, $changedFieldKeys);

        $this->write($record, $fields);

        return array_map(static fn (FieldDefinition $field): string => $field->key, $fields);
    }
}
