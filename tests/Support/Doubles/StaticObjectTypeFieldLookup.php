<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeFieldLookup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StaticObjectTypeFieldLookup extends ObjectTypeFieldLookup
{
    /**
     * @param  array<string, list<FieldDefinition>>  $fieldsByObjectType
     * @param  array<string, ObjectType>  $objectTypes
     * @param  array<string, string>  $relationshipTargets
     */
    public function __construct(
        private array $fieldsByObjectType = [],
        private array $objectTypes = [],
        private array $relationshipTargets = [],
    ) {}

    /**
     * @param  list<FieldDefinition>  $fields
     */
    public static function carrying(string $objectTypeId, array $fields): self
    {
        return new self([$objectTypeId => $fields]);
    }

    /**
     * @param  list<FieldDefinition>  $fields
     */
    public function withFields(string $objectTypeId, array $fields): self
    {
        $this->fieldsByObjectType[$objectTypeId] = $fields;

        return $this;
    }

    public function withObjectType(ObjectType $objectType): self
    {
        $this->objectTypes[(string) $objectType->getKey()] = $objectType;

        return $this;
    }

    public function withRelationshipTarget(string $relationshipTypeId, string $objectTypeId): self
    {
        $this->relationshipTargets[$relationshipTypeId] = $objectTypeId;

        return $this;
    }

    public function relationshipTarget(string $relationshipTypeId): ?string
    {
        return $this->relationshipTargets[$relationshipTypeId] ?? null;
    }

    public function objectTypeOrFail(string $objectTypeId): ObjectType
    {
        $objectType = $this->objectTypes[$objectTypeId] ?? null;

        if (!$objectType instanceof ObjectType) {
            throw (new ModelNotFoundException)->setModel(ObjectType::class, [$objectTypeId]);
        }

        return $objectType;
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function fields(string $objectTypeId): Collection
    {
        return new Collection($this->fieldsByObjectType[$objectTypeId] ?? []);
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
}
