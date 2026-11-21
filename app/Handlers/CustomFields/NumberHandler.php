<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;

class NumberHandler extends AbstractFieldHandler
{
    public function cast(mixed $value, FieldDefinition $field): mixed
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public function fieldType(): FieldType
    {
        return FieldType::Number;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return FilterOperator::forNumber();
    }

    /**
     * @return array<int, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return ['integer'];
    }
}
