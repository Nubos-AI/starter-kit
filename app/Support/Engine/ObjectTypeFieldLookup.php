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
    /** @var array<string, RelationshipType|null> */
    private array $relationships = [];

    /** @var array<string, ObjectType> */
    private array $objectTypes = [];

    /** @var array<string, Collection<int, FieldDefinition>> */
    private array $fields = [];

    public function relationship(string $relationshipTypeId): ?RelationshipType
    {
        if (array_key_exists($relationshipTypeId, $this->relationships)) {
            return $this->relationships[$relationshipTypeId];
        }

        $relationship = RelationshipType::query()->whereKey($relationshipTypeId)->first();

        return $this->relationships[$relationshipTypeId] = $relationship instanceof RelationshipType ? $relationship : null;
    }

    public function relationshipTarget(string $relationshipTypeId): ?string
    {
        $relationship = $this->relationship($relationshipTypeId);

        return $relationship instanceof RelationshipType ? (string) $relationship->to_object_type_id : null;
    }

    public function objectTypeOrFail(string $objectTypeId): ObjectType
    {
        return $this->objectTypes[$objectTypeId] ??= ObjectType::query()->whereKey($objectTypeId)->firstOrFail();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function fields(string $objectTypeId): Collection
    {
        return $this->fields[$objectTypeId] ??= FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->get();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function filterableFields(string $objectTypeId): Collection
    {
        return $this->fields($objectTypeId)
            ->filter(static fn (FieldDefinition $field): bool => (bool) $field->is_filterable)
            ->values();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function computedFields(string $objectTypeId): Collection
    {
        return $this->fields($objectTypeId)
            ->filter(static fn (FieldDefinition $field): bool => in_array(
                $field->field_type,
                [FieldType::Rollup, FieldType::Computed],
                true,
            ))
            ->values();
    }

    public function forget(?string $objectTypeId = null): void
    {
        $this->relationships = [];

        if ($objectTypeId === null) {
            $this->objectTypes = [];
            $this->fields = [];

            return;
        }

        unset($this->objectTypes[$objectTypeId], $this->fields[$objectTypeId]);
    }
}
