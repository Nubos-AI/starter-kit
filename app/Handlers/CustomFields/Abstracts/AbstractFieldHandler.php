<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields\Abstracts;

use App\Contracts\CustomFields\FieldHandler;
use App\Enums\CustomFields\FilterOperator;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;

abstract class AbstractFieldHandler implements FieldHandler
{
    public function cast(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : $value;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return [];
    }

    public function toSearchable(mixed $value, FieldDefinition $field): mixed
    {
        if ($field->is_encrypted) {
            return null;
        }

        return $this->searchableValue($value, $field);
    }

    public function default(FieldDefinition $field): mixed
    {
        return $field->default_value;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return [
            FilterOperator::Equals,
            FilterOperator::NotEqual,
            FilterOperator::Blank,
            FilterOperator::NotBlank,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function renderConfig(FieldDefinition $field): array
    {
        return $field->config ?? [];
    }

    protected function searchableValue(mixed $value, FieldDefinition $field): mixed
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * @return list<mixed>
     */
    protected function options(FieldDefinition $field): array
    {
        $lookup = $field->config['lookup_object_type'] ?? null;

        if (is_string($lookup) && $lookup !== '') {
            return $this->lookupOptions($lookup);
        }

        $options = $field->config['options'] ?? [];

        return is_array($options) ? array_values($options) : [];
    }

    /**
     * @return list<mixed>
     */
    private function lookupOptions(string $reference): array
    {
        $objectType = ObjectType::query()
            ->where('slug', $reference)
            ->orWhere('id', $reference)
            ->first();

        if (!$objectType instanceof ObjectType) {
            return [];
        }

        $values = CustomRecord::query()
            ->ofType($objectType)
            ->get()
            ->map(static function (CustomRecord $record): mixed {
                $data = $record->data;

                return is_array($data) ? ($data['value'] ?? null) : null;
            })
            ->reject(static fn (mixed $value): bool => $value === null)
            ->all();

        return array_values($values);
    }
}
