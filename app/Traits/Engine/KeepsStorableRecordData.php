<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Models\FieldDefinition;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

trait KeepsStorableRecordData
{
    /**
     * @param  Collection<array-key, FieldDefinition>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storableData(Collection $fields, array $data): array
    {
        $storableKeys = $fields
            ->reject(static fn (FieldDefinition $field): bool => $field->field_type->isDerived())
            ->map(static fn (FieldDefinition $field): string => $field->key)
            ->values()
            ->all();

        return Arr::only($data, $storableKeys);
    }
}
