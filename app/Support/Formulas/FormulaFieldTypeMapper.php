<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\FormulaValueType;
use App\Models\FieldDefinition;

class FormulaFieldTypeMapper
{
    public function resolveField(string $objectTypeId, string $fieldKey): ?FieldDefinition
    {
        return FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('key', $fieldKey)
            ->first();
    }

    public function map(FieldDefinition $field): ?FormulaValueType
    {
        return match ($field->field_type) {
            FieldType::Number,
            FieldType::Decimal,
            FieldType::Money,
            FieldType::Rollup => FormulaValueType::Number,
            FieldType::TextShort,
            FieldType::TextLong,
            FieldType::Email,
            FieldType::Phone,
            FieldType::Url,
            FieldType::SingleSelect => FormulaValueType::Text,
            FieldType::Date, FieldType::DateTime => FormulaValueType::Date,
            FieldType::Boolean => FormulaValueType::Boolean,
            FieldType::Computed => $field->resultType(),
            default => null,
        };
    }
}
