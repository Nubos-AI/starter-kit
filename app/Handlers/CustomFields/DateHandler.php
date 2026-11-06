<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;

class DateHandler extends AbstractFieldHandler
{
    public function fieldType(): FieldType
    {
        return FieldType::Date;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return FilterOperator::forDate();
    }

    /**
     * @return array<int, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return ['date_format:Y-m-d'];
    }
}
