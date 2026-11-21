<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\CustomFields\FieldType;
use App\Enums\Import\ImportMissingOptionMode;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;

class MissingOptionResolver
{
    public function effectiveMode(ImportMissingOptionMode $requested, User $user, ObjectType $objectType): ImportMissingOptionMode
    {
        if ($requested === ImportMissingOptionMode::Create && !$user->can('update', $objectType)) {
            return ImportMissingOptionMode::Error;
        }

        return $requested;
    }

    public function isInlineSelect(FieldDefinition $field): bool
    {
        if ($field->field_type !== FieldType::SingleSelect && $field->field_type !== FieldType::MultiSelect) {
            return false;
        }

        $lookup = $field->config['lookup_object_type'] ?? null;

        return !is_string($lookup) || $lookup === '';
    }

    /**
     * @return list<string>
     */
    public function options(FieldDefinition $field): array
    {
        $options = $field->config['options'] ?? [];

        if (!is_array($options)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $option): string => is_scalar($option) ? (string) $option : '',
            $options,
        ));
    }

    /**
     * @return list<string>
     */
    public function missingValues(FieldDefinition $field, mixed $value): array
    {
        if (!$this->isInlineSelect($field)) {
            return [];
        }

        $known = $this->options($field);

        $missing = [];

        foreach ($this->valuesOf($value) as $candidate) {
            if (!in_array($candidate, $known, true) && !in_array($candidate, $missing, true)) {
                $missing[] = $candidate;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function valuesOf(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        $result = [];

        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $trimmed = trim((string) $item);

            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }
}
