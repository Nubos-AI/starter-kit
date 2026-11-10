<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SearchableObjectTypes
{
    /**
     * @param  (Closure(ObjectType): bool)|null  $objectTypeFilter
     * @param  EloquentCollection<int, ObjectType>|null  $objectTypes
     * @param  EloquentCollection<int, FieldDefinition>|null  $fieldDefinitions
     * @return array<string, array{objectType: ObjectType, attributes: non-empty-list<non-empty-string>, isRestricted: bool}>
     */
    public static function for(
        User $user,
        ?Closure $objectTypeFilter = null,
        ?EloquentCollection $objectTypes = null,
        ?EloquentCollection $fieldDefinitions = null,
    ): array {
        $visible = ($objectTypes ?? ObjectType::query()->get())
            ->filter(static fn (ObjectType $objectType): bool => $user->hasPermission("{$objectType->slug}.view")
                && ($objectTypeFilter === null || $objectTypeFilter($objectType)))
            ->keyBy(static fn (ObjectType $objectType): string => (string) $objectType->getKey())
            ->all();

        if ($visible === []) {
            return [];
        }

        $searchableKeys = self::searchableFieldKeys(array_keys($visible), $fieldDefinitions);
        $resolver = FieldVisibilityResolver::forRequest();
        $searchable = [];

        foreach ($visible as $objectTypeId => $objectType) {
            $keys = $searchableKeys[$objectTypeId] ?? [];
            $readable = array_values(array_intersect($keys, $resolver->readableFieldKeys($user, $objectTypeId)));

            if ($readable === []) {
                continue;
            }

            $searchable[$objectTypeId] = [
                'objectType' => $objectType,
                'attributes' => $readable,
                'isRestricted' => count($readable) < count($keys),
            ];
        }

        return $searchable;
    }

    /**
     * @param  list<string>  $objectTypeIds
     * @param  EloquentCollection<int, FieldDefinition>|null  $fieldDefinitions
     * @return array<string, list<non-empty-string>>
     */
    private static function searchableFieldKeys(array $objectTypeIds, ?EloquentCollection $fieldDefinitions): array
    {
        $keys = [];

        $fields = $fieldDefinitions ?? FieldDefinition::query()
            ->whereIn('object_type_id', $objectTypeIds)
            ->where('is_searchable', true)
            ->where('is_encrypted', false)
            ->get(['object_type_id', 'key', 'is_searchable', 'is_encrypted']);

        foreach ($fields as $field) {
            if ($field->key === '' || !$field->is_searchable || $field->is_encrypted) {
                continue;
            }

            if (!in_array((string) $field->object_type_id, $objectTypeIds, true)) {
                continue;
            }

            $keys[$field->object_type_id][] = $field->key;
        }

        return $keys;
    }
}
