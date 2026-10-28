<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;

class TextLongHandler extends AbstractFieldHandler
{
    public function fieldType(): FieldType
    {
        return FieldType::TextLong;
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
        return ['string'];
    }

    /**
     * @return array<string, mixed>
     */
    public function renderConfig(FieldDefinition $field): array
    {
        return ['multiline' => true] + parent::renderConfig($field);
    }
}
