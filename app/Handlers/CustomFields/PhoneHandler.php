<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;

class PhoneHandler extends AbstractFieldHandler
{
    public function fieldType(): FieldType
    {
        return FieldType::Phone;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return FilterOperator::forText();
    }

    /**
     * @return array<int, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        $rules = ['string', 'max:255'];

        $pattern = $field->config['regex'] ?? null;

        if (is_string($pattern) && $pattern !== '') {
            $rules[] = 'regex:'.$pattern;
        }

        return $rules;
    }
}
