<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\Report;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;

class ReportLinkedFieldEnumerator
{
    private string $qualifiedSegmentPattern = '/^[a-z][a-z0-9_]*$/';

    /**
     * @return list<array<string, mixed>>
     */
    public function payload(User $user, ObjectType $objectType): array
    {
        $objectTypeId = (string) $objectType->getKey();

        $relations = $this->relationFields($user, $objectTypeId);

        if ($relations->isEmpty()) {
            return [];
        }

        $relationshipTypes = $this->relationshipTypes($relations, $objectTypeId);

        if ($relationshipTypes->isEmpty()) {
            return [];
        }

        $targetTypes = $this->targetTypes($user, $relationshipTypes);

        if ($targetTypes === []) {
            return [];
        }

        $targetFields = $this->targetFields(array_keys($targetTypes));

        $payload = [];

        foreach ($relations as $relation) {
            $relationshipType = $relationshipTypes->get($this->relationshipTypeId($relation));

            if (!$relationshipType instanceof RelationshipType) {
                continue;
            }

            $targetId = $relationshipType->to_object_type_id;

            if (!isset($targetTypes[$targetId])) {
                continue;
            }

            $readable = FieldVisibilityResolver::forRequest()->readableFieldKeys($user, $targetId);

            foreach ($targetFields[$targetId] ?? [] as $field) {
                if (!in_array($field->key, $readable, true)) {
                    continue;
                }

                $entry = $this->entry($relation, $field);

                if ($entry !== null) {
                    $payload[] = $entry;
                }
            }
        }

        return $payload;
    }

    /**
     * @return EloquentCollection<int, FieldDefinition>
     */
    private function relationFields(User $user, string $objectTypeId): EloquentCollection
    {
        $readable = FieldVisibilityResolver::forRequest()->readableFieldKeys($user, $objectTypeId);

        /** @var EloquentCollection<int, FieldDefinition> $relations */
        $relations = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->whereIn('field_type', [
                FieldType::RelationHasMany->value,
                FieldType::RelationManyToMany->value,
            ])
            ->orderByRaw('list_position is null, list_position')
            ->orderBy('key')
            ->get()
            ->filter(fn (FieldDefinition $field): bool => in_array($field->key, $readable, true)
                && $this->relationshipTypeId($field) !== '')
            ->values();

        return $relations;
    }

    /**
     * @param  EloquentCollection<int, FieldDefinition>  $relations
     * @return EloquentCollection<string, RelationshipType>
     */
    private function relationshipTypes(EloquentCollection $relations, string $objectTypeId): EloquentCollection
    {
        /** @var EloquentCollection<string, RelationshipType> $types */
        $types = RelationshipType::query()
            ->whereKey($relations->map(fn (FieldDefinition $field): string => $this->relationshipTypeId($field))->all())
            ->where('from_object_type_id', $objectTypeId)
            ->get()
            ->keyBy(fn (RelationshipType $type): string => (string) $type->getKey());

        return $types;
    }

    /**
     * @param  EloquentCollection<string, RelationshipType>  $relationshipTypes
     * @return array<string, ObjectType>
     */
    private function targetTypes(User $user, EloquentCollection $relationshipTypes): array
    {
        $ids = $relationshipTypes
            ->map(fn (RelationshipType $type): string => $type->to_object_type_id)
            ->unique()
            ->all();

        $allowed = [];

        foreach (ObjectType::query()->whereKey($ids)->get() as $type) {
            if (Gate::forUser($user)->allows('create', [Report::class, $type])) {
                $allowed[(string) $type->getKey()] = $type;
            }
        }

        return $allowed;
    }

    /**
     * @param  array<int, string>  $objectTypeIds
     * @return array<string, list<FieldDefinition>>
     */
    private function targetFields(array $objectTypeIds): array
    {
        $grouped = [];

        $fields = FieldDefinition::query()
            ->whereIn('object_type_id', $objectTypeIds)
            ->orderByRaw('list_position is null, list_position')
            ->orderBy('key')
            ->get();

        foreach ($fields as $field) {
            $grouped[$field->object_type_id][] = $field;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(FieldDefinition $relation, FieldDefinition $field): ?array
    {
        if (preg_match($this->qualifiedSegmentPattern, $relation->key) !== 1
            || preg_match($this->qualifiedSegmentPattern, $field->key) !== 1) {
            return null;
        }

        return [
            'key' => "{$relation->key}.{$field->key}",
            'field_type' => $field->field_type->value,
            'label' => $this->label($relation).' › '.$this->label($field),
            'is_required' => false,
            'is_sortable' => false,
            'is_filterable' => false,
            'is_default_column' => false,
            'list_position' => null,
            'config' => $field->config,
            'validation_rules' => $field->validation_rules,
            'default_value' => $field->default_value,
        ];
    }

    private function relationshipTypeId(FieldDefinition $field): string
    {
        $id = ($field->config ?? [])['relationship_type_id'] ?? null;

        return is_string($id) ? $id : '';
    }

    private function label(FieldDefinition $field): string
    {
        $label = (new TranslatableValueResolver)->resolve($field->i18n_labels);

        return is_string($label) && $label !== '' ? $label : $field->key;
    }
}
