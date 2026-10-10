<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\User;
use App\Support\Engine\ObjectTypeFieldLookup;
use Closure;
use Illuminate\Database\Eloquent\Collection;

class FieldVisibilityResolver
{
    /**
     * @var array<string, array{read: list<string>, write: list<string>, fields: list<string>}>
     */
    private array $memo = [];

    public function __construct(
        private readonly ObjectTypeFieldLookup $fieldLookup,
        private readonly AuthorizationDirectory $directory,
    ) {}

    public static function forRequest(): self
    {
        if (!app()->bound(self::class)) {
            /** @var self $created */
            $created = app()->make(self::class);

            app()->instance(self::class, $created);
        }

        /** @var self $resolver */
        $resolver = app()->make(self::class);

        return $resolver;
    }

    /**
     * @return list<string>
     */
    public function readableFieldKeys(User $user, string $objectTypeId): array
    {
        return $this->resolve($user, $objectTypeId)['read'];
    }

    /**
     * @return list<string>
     */
    public function forbiddenReadFieldKeys(User $user, string $objectTypeId): array
    {
        $resolved = $this->resolve($user, $objectTypeId);

        return array_values(array_diff($resolved['fields'], $resolved['read']));
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function readableFields(User $user, ObjectType $objectType): Collection
    {
        $objectType->loadMissing('fieldDefinitions');
        $forbidden = $this->forbiddenReadFieldKeys($user, (string) $objectType->getKey());

        return $objectType->fieldDefinitions
            ->filter(static fn (FieldDefinition $field): bool => !$field->is_encrypted && !in_array($field->key, $forbidden, true))
            ->values();
    }

    /**
     * @return list<string>
     */
    public function forbiddenWriteFieldKeys(User $user, string $objectTypeId): array
    {
        $resolved = $this->resolve($user, $objectTypeId);

        return array_values(array_diff($resolved['fields'], $resolved['write']));
    }

    /**
     * @return array{read: list<string>, write: list<string>, fields: list<string>}
     */
    private function resolve(User $user, string $objectTypeId): array
    {
        $roleIds = array_values($user->rolesFor()
            ->map(static fn (Role $role): string => (string) $role->getKey())
            ->sort()
            ->all());
        $signature = implode(',', $roleIds);
        $key = $user->getKey().'|'.$signature.'|'.$objectTypeId;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        /** @var list<array{id: string, key: string}> $fields */
        $fields = $this->fieldLookup->fields($objectTypeId)
            ->map(static fn (FieldDefinition $field): array => ['id' => (string) $field->getKey(), 'key' => $field->key])
            ->all();

        $allKeys = array_map(static fn (array $field): string => $field['key'], $fields);

        if ($user->isEscalatedAuthority()) {
            return $this->memo[$key] = ['read' => $allKeys, 'write' => $allKeys, 'fields' => $allKeys];
        }

        $fieldIds = array_map(static fn (array $field): string => $field['id'], $fields);

        $restrictions = $this->directory->fieldGrantsOfFields($fieldIds);

        $read = [];
        $write = [];

        foreach ($fields as $field) {
            $fieldRestrictions = $restrictions
                ->where('field_definition_id', $field['id'])
                ->keyBy('role_id');

            if ($this->anyRoleMay($roleIds, $fieldRestrictions, static fn (FieldPermission $restriction): bool => $restriction->can_read)) {
                $read[] = $field['key'];
            }

            if ($this->anyRoleMay($roleIds, $fieldRestrictions, static fn (FieldPermission $restriction): bool => $restriction->can_write)) {
                $write[] = $field['key'];
            }
        }

        return $this->memo[$key] = ['read' => $read, 'write' => $write, 'fields' => $allKeys];
    }

    /**
     * @param  list<string>  $roleIds
     * @param  Collection<string, FieldPermission>  $restrictions
     * @param  Closure(FieldPermission): bool  $allows
     */
    private function anyRoleMay(array $roleIds, Collection $restrictions, Closure $allows): bool
    {
        if ($roleIds === []) {
            return true;
        }

        foreach ($roleIds as $roleId) {
            $restriction = $restrictions->get($roleId);

            if ($restriction === null || $allows($restriction)) {
                return true;
            }
        }

        return false;
    }
}
