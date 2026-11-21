<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;
use Illuminate\Validation\Rule;

class MultiSelectHandler extends AbstractFieldHandler
{
    public function cast(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return array_values(array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            (array) $value,
        ));
    }

    public function fieldType(): FieldType
    {
        return FieldType::MultiSelect;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return FilterOperator::forChoice();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return [
            'array',
            '*' => [Rule::in($this->options($field))],
        ];
    }

    protected function searchableValue(mixed $value, FieldDefinition $field): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        return implode(', ', array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            $value,
        ));
    }
}
