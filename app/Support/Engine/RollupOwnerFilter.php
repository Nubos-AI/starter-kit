<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\Tenancy\TenantContext;

class RollupOwnerFilter
{
    public function __construct(
        private readonly ObjectTypeFieldLookup $fieldLookup,
        private readonly RollupChildResolver $childResolver,
    ) {}

    public function ownsRollups(string $tenantId, string $objectTypeId, ?string $relationshipTypeId = null): bool
    {
        if (TenantContext::currentId() !== $tenantId) {
            return true;
        }

        $fields = $this->fieldLookup->fields($objectTypeId);

        if ($fields->isEmpty()) {
            return true;
        }

        return $fields->contains(fn (FieldDefinition $field): bool => $field->field_type === FieldType::Rollup
            && ($relationshipTypeId === null || in_array($this->childResolver->relationshipTypeIdOf($field), ['', $relationshipTypeId], true)));
    }
}
