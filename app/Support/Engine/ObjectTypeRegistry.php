<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordRelationDescriptor;
use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ObjectTypeRegistry
{
    /** @var array<string, ObjectType> */
    private array $byId = [];

    /** @var array<string, string> */
    private array $idBySlug = [];

    /** @var array<string, string> */
    private array $idByKey = [];

    /** @var array<string, Collection<string, FieldDefinition>> */
    private array $fields = [];

    /** @var array<string, EloquentCollection<int, RelationshipType>> */
    private array $relationshipTypes = [];

    /** @var array<string, array<string, RecordRelationDescriptor>> */
    private array $descriptors = [];

    /** @var array<string, int>|null */
    private ?array $relationNames = null;

    public function byId(string $objectTypeId): ObjectType
    {
        $objectType = $this->find($objectTypeId);

        if (!$objectType instanceof ObjectType) {
            throw (new ModelNotFoundException)->setModel(ObjectType::class, [$objectTypeId]);
        }

        return $objectType;
    }

    public function bySlug(string $slug): ObjectType
    {
        $cached = $this->idBySlug[$slug] ?? null;

        if ($cached !== null) {
            return $this->byId[$cached];
        }

        return $this->remember(
            ObjectType::query()->where('slug', $slug)->firstOrFail(),
        );
    }

    public function byKey(string $key): ObjectType
    {
        $cached = $this->idByKey[$key] ?? null;

        if ($cached !== null) {
            return $this->byId[$cached];
        }

        return $this->remember(
            ObjectType::query()->where('key', $key)->firstOrFail(),
        );
    }

    public function find(string $objectTypeId): ?ObjectType
    {
        if (array_key_exists($objectTypeId, $this->byId)) {
            return $this->byId[$objectTypeId];
        }

        $objectType = ObjectType::query()->whereKey($objectTypeId)->first();

        return $objectType instanceof ObjectType ? $this->remember($objectType) : null;
    }

    public function forRecord(CustomRecord $record): ObjectType
    {
        return $this->byId($record->object_type_id);
    }

    /**
     * @return Collection<string, FieldDefinition>
     */
    public function fields(string $objectTypeId): Collection
    {
        return $this->fields[$objectTypeId] ??= FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->orderBy('list_position')
            ->orderBy('created_at')
            ->get()
            ->keyBy('key');
    }

    public function field(string $objectTypeId, string $fieldKey): ?FieldDefinition
    {
        return $this->fields($objectTypeId)->get($fieldKey);
    }

    /**
     * @return EloquentCollection<int, RelationshipType>
     */
    public function relationshipTypes(string $objectTypeId): EloquentCollection
    {
        return $this->relationshipTypes[$objectTypeId] ??= RelationshipType::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('from_object_type_id', $objectTypeId)
                ->orWhere('to_object_type_id', $objectTypeId))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, RecordRelationDescriptor>
     */
    public function relationDescriptors(string $objectTypeId): array
    {
        return $this->descriptors[$objectTypeId] ??= $this->buildDescriptors($objectTypeId);
    }

    /**
     * @return class-string<CustomRecord>|null
     */
    public function modelClassFor(ObjectType|string $type): ?string
    {
        /** @var array<string, class-string<CustomRecord>> $models */
        $models = config('engine.record_models', []);

        return $models[$type instanceof ObjectType ? $type->slug : $type] ?? null;
    }

    public function isRelationName(string $name): bool
    {
        $this->relationNames ??= RelationshipType::query()
            ->get(['key', 'inverse_key'])
            ->flatMap(static fn (RelationshipType $type): array => [
                Str::camel($type->key),
                Str::camel($type->inverse_key),
            ])
            ->flip()
            ->all();

        return array_key_exists($name, $this->relationNames);
    }

    public function forget(?string $objectTypeId = null): void
    {
        if ($objectTypeId === null) {
            $this->byId = [];
            $this->idBySlug = [];
            $this->idByKey = [];
            $this->fields = [];
            $this->relationshipTypes = [];
            $this->descriptors = [];
            $this->relationNames = null;

            return;
        }

        unset(
            $this->byId[$objectTypeId],
            $this->fields[$objectTypeId],
            $this->relationshipTypes[$objectTypeId],
            $this->descriptors[$objectTypeId],
        );

        $this->idBySlug = array_filter($this->idBySlug, fn (string $id): bool => $id !== $objectTypeId);
        $this->idByKey = array_filter($this->idByKey, fn (string $id): bool => $id !== $objectTypeId);
        $this->relationNames = null;
    }

    /**
     * @return array<string, RecordRelationDescriptor>
     */
    private function buildDescriptors(string $objectTypeId): array
    {
        $descriptors = [];

        foreach ($this->relationshipTypes($objectTypeId) as $type) {
            foreach ($this->directionsOf($type, $objectTypeId) as $direction) {
                $descriptor = $this->describe($type, $direction);
                $descriptors[$descriptor->name] = $descriptor;
            }
        }

        return $descriptors;
    }

    /**
     * @return list<RelationDirection>
     */
    private function directionsOf(RelationshipType $type, string $objectTypeId): array
    {
        $directions = [];

        if ($type->from_object_type_id === $objectTypeId) {
            $directions[] = RelationDirection::Outgoing;
        }

        if ($type->to_object_type_id === $objectTypeId) {
            $directions[] = RelationDirection::Incoming;
        }

        return $directions;
    }

    private function describe(RelationshipType $type, RelationDirection $direction): RecordRelationDescriptor
    {
        return new RecordRelationDescriptor(
            name: Str::camel($direction->isOutgoing() ? $type->key : $type->inverse_key),
            relationshipTypeId: (string) $type->getKey(),
            direction: $direction,
            cardinality: $type->cardinality,
            isHierarchy: $type->is_hierarchy,
            counterpartObjectTypeId: $direction->isOutgoing()
                ? $type->to_object_type_id
                : $type->from_object_type_id,
        );
    }

    private function remember(ObjectType $objectType): ObjectType
    {
        $id = (string) $objectType->getKey();

        $this->byId[$id] = $objectType;
        $this->idBySlug[$objectType->slug] = $id;
        $this->idByKey[$objectType->key] = $id;

        return $objectType;
    }
}
