<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use Illuminate\Database\Eloquent\Collection;

class ObjectTypeFieldLookup
{
    public function relationship(string $relationshipTypeId): ?RelationshipType
    {
        $relationship = RelationshipType::query()->whereKey($relationshipTypeId)->first();

        return $relationship instanceof RelationshipType ? $relationship : null;
    }

    public function relationshipTarget(string $relationshipTypeId): ?string
    {
        $relationship = $this->relationship($relationshipTypeId);

        return $relationship instanceof RelationshipType ? (string) $relationship->to_object_type_id : null;
    }

    public function objectTypeOrFail(string $objectTypeId): ObjectType
    {
        return ObjectType::query()->whereKey($objectTypeId)->firstOrFail();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function fields(string $objectTypeId): Collection
    {
        return FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->get();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function filterableFields(string $objectTypeId): Collection
    {
        return FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('is_filterable', true)
            ->get();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function computedFields(string $objectTypeId): Collection
    {
        return FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->whereIn('field_type', [FieldType::Rollup, FieldType::Computed])
            ->get();
    }
}
