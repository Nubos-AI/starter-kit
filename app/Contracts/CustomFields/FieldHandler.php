<?php

declare(strict_types=1);

namespace App\Contracts\CustomFields;

use App\Enums\CustomFields\FilterOperator;
use App\Models\FieldDefinition;

interface FieldHandler extends FieldTypeHandler
{
    public function cast(mixed $value, FieldDefinition $field): mixed;

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array;

    /**
     * @return array<int|string, mixed>
     */
    public function validationRules(FieldDefinition $field): array;

    public function toSearchable(mixed $value, FieldDefinition $field): mixed;

    public function default(FieldDefinition $field): mixed;

    /**
     * @return array<string, mixed>
     */
    public function renderConfig(FieldDefinition $field): array;
}
