<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\ObjectType;
use App\Models\Role;

class FieldPermissionMatrix
{
    /**
     * @return list<array{
     *     objectType: array{id: string, key: string, slug: string, name: string},
     *     fields: list<array{id: string, key: string, read: bool, write: bool}>,
     * }>
     */
    public static function forRole(Role $role): array
    {
        $objectTypes = ObjectType::query()->orderBy('name')->get(['id', 'key', 'slug', 'name']);

        $fields = FieldDefinition::query()
            ->whereIn('object_type_id', $objectTypes->modelKeys())
            ->orderByRaw('list_position is null, list_position')
            ->orderBy('key')
            ->get(['id', 'object_type_id', 'key']);

        $restrictions = $role->authority !== null
            ? collect()
            : FieldPermission::query()
                ->where('role_id', $role->getKey())
                ->whereIn('field_definition_id', $fields->modelKeys())
                ->get(['field_definition_id', 'can_read', 'can_write'])
                ->keyBy('field_definition_id');

        $fieldsByObjectType = $fields->groupBy('object_type_id');

        return array_values($objectTypes->map(fn (ObjectType $objectType): array => [
            'objectType' => [
                'id' => $objectType->id,
                'key' => $objectType->key,
                'slug' => $objectType->slug,
                'name' => $objectType->name,
            ],
            'fields' => array_values($fieldsByObjectType
                ->get($objectType->getKey(), collect())
                ->map(fn (FieldDefinition $field): array => [
                    'id' => $field->id,
                    'key' => $field->key,
                    'read' => (bool) ($restrictions[$field->id]->can_read ?? true),
                    'write' => (bool) ($restrictions[$field->id]->can_write ?? true),
                ])
                ->all()),
        ])->all());
    }

    /**
     * @return array{
     *     roles: list<array{id: string, name: string, bypassesFieldPermissions: bool}>,
     *     fields: list<array{id: string, key: string, restricted: bool}>,
     *     grants: array<string, array<string, array{read: bool, write: bool}>>,
     * }
     */
    public static function forObjectType(ObjectType $objectType): array
    {
        $roles = Role::query()->orderBy('name')->get(['id', 'name', 'authority']);

        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderByRaw('list_position is null, list_position')
            ->orderBy('key')
            ->get(['id', 'key']);

        $permissions = FieldPermission::query()
            ->whereIn('field_definition_id', $fields->modelKeys())
            ->get(['field_definition_id', 'role_id', 'can_read', 'can_write']);

        $restrictedFieldIds = $permissions->pluck('field_definition_id')->unique()->flip();

        $grants = [];

        foreach ($roles as $role) {
            if ($role->authority !== null) {
                $grants[(string) $role->getKey()] = [];

                continue;
            }

            $grants[(string) $role->getKey()] = $permissions
                ->where('role_id', $role->getKey())
                ->mapWithKeys(fn (FieldPermission $permission): array => [
                    $permission->field_definition_id => [
                        'read' => $permission->can_read,
                        'write' => $permission->can_write,
                    ],
                ])
                ->all();
        }

        return [
            'roles' => array_values($roles->map(fn (Role $role): array => [
                'id' => (string) $role->getKey(),
                'name' => $role->name,
                'bypassesFieldPermissions' => $role->authority !== null,
            ])->all()),
            'fields' => array_values($fields->map(fn (FieldDefinition $field): array => [
                'id' => (string) $field->getKey(),
                'key' => $field->key,
                'restricted' => isset($restrictedFieldIds[$field->id]),
            ])->all()),
            'grants' => $grants,
        ];
    }
}
