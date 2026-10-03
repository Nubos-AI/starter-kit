<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;

/**
 * @phpstan-type GeoValue array{lat: float|null, lng: float|null, label: string|null}
 */
class GeoAddressHandler extends AbstractFieldHandler
{
    /**
     * @return GeoValue|null
     */
    public function cast(mixed $value, FieldDefinition $field): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return [
            'lat' => isset($value['lat']) && is_numeric($value['lat']) ? (float) $value['lat'] : null,
            'lng' => isset($value['lng']) && is_numeric($value['lng']) ? (float) $value['lng'] : null,
            'label' => isset($value['label']) && is_scalar($value['label']) ? (string) $value['label'] : null,
        ];
    }

    public function fieldType(): FieldType
    {
        return FieldType::GeoAddress;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return FilterOperator::forPresence();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function searchableValue(mixed $value, FieldDefinition $field): mixed
    {
        if (is_array($value) && isset($value['label']) && is_scalar($value['label'])) {
            return (string) $value['label'];
        }

        return null;
    }
}
