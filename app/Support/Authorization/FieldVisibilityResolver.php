<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\Engine\FieldDependencyGraphGuard;
use App\Support\Engine\ObjectTypeFieldLookup;
use Closure;
use Illuminate\Database\Eloquent\Collection;

class FieldVisibilityResolver
{
    /**
     * @var array<string, array{read: list<string>, write: list<string>, fields: list<string>}>
     */
    private array $memo = [];

    /**
     * @var array<string, true>
     */
    private array $resolving = [];

    public function __construct(
        private readonly ObjectTypeFieldLookup $fieldLookup,
        private readonly AuthorizationDirectory $directory,
        private readonly FieldDependencyGraphGuard $dependencies,
        private readonly RowAccessEnforcement $rowAccess,
        private readonly TeamAccessRuleResolver $teamAccessRules,
    ) {}

    public static function forRequest(): self
    {
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
        $enforcement = $this->rowAccess->isEnabled() ? 'enforced' : 'open';
        $key = "{$user->getKey()}|{$signature}|{$objectTypeId}|{$enforcement}";

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

        if (isset($this->resolving[$key])) {
            return ['read' => [], 'write' => [], 'fields' => $allKeys];
        }

        $this->resolving[$key] = true;

        try {
            [$read, $write] = $this->grantedReadAndWrite($roleIds, $fields);

            $read = $this->withoutUnreadableComputedFields($user, $objectTypeId, $read, array_values(array_diff($allKeys, $read)));
        } finally {
            unset($this->resolving[$key]);
        }

        $resolved = ['read' => $read, 'write' => $write, 'fields' => $allKeys];

        if ($this->resolving !== []) {
            return $resolved;
        }

        return $this->memo[$key] = $resolved;
    }

    /**
     * @param  list<string>  $roleIds
     * @param  list<array{id: string, key: string}>  $fields
     * @return array{0: list<string>, 1: list<string>}
     */
    private function grantedReadAndWrite(array $roleIds, array $fields): array
    {
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

        return [$read, $write];
    }

    /**
     * @param  list<string>  $read
     * @param  list<string>  $forbidden
     * @return list<string>
     */
    private function withoutUnreadableComputedFields(User $user, string $objectTypeId, array $read, array $forbidden): array
    {
        $computed = $this->fieldLookup->computedFields($objectTypeId)->keyBy('key');

        if ($computed->isEmpty()) {
            return $read;
        }

        /** @var array<string, bool> $verdicts */
        $verdicts = [];

        foreach ($computed as $field) {
            $this->isComputedFieldReadable($user, $field, $computed, $forbidden, $verdicts);
        }

        return array_values(array_filter($read, static fn (string $key): bool => $verdicts[$key] ?? true));
    }

    /**
     * @param  Collection<string, FieldDefinition>  $computed
     * @param  list<string>  $forbidden
     * @param  array<string, bool>  $verdicts
     */
    private function isComputedFieldReadable(User $user, FieldDefinition $field, Collection $computed, array $forbidden, array &$verdicts): bool
    {
        if (isset($verdicts[$field->key])) {
            return $verdicts[$field->key];
        }

        $verdicts[$field->key] = false;

        if (in_array($field->key, $forbidden, true)) {
            return false;
        }

        if ($field->field_type === FieldType::Rollup) {
            return $verdicts[$field->key] = $this->mayReadRollupSources($user, $field);
        }

        foreach ($this->dependencies->dependenciesOf($field) as $dependencyKey) {
            $dependency = $computed->get($dependencyKey);

            $isReadable = $dependency instanceof FieldDefinition
                ? $this->isComputedFieldReadable($user, $dependency, $computed, $forbidden, $verdicts)
                : !in_array($dependencyKey, $forbidden, true);

            if (!$isReadable) {
                return false;
            }
        }

        return $verdicts[$field->key] = true;
    }

    private function mayReadRollupSources(User $user, FieldDefinition $field): bool
    {
        $relationshipTypeId = is_array($field->config) ? ($field->config['relationship_type_id'] ?? null) : null;
        $childObjectTypeId = is_string($relationshipTypeId) && $relationshipTypeId !== ''
            ? $this->fieldLookup->relationshipTarget($relationshipTypeId)
            : null;

        if ($childObjectTypeId === null) {
            return false;
        }

        if ($this->isNarrowedByRowAccess($user, $childObjectTypeId)) {
            return false;
        }

        $forbiddenOnChild = $this->forbiddenReadFieldKeys($user, $childObjectTypeId);

        return array_intersect($this->dependencies->dependenciesOf($field), $forbiddenOnChild) === [];
    }

    private function isNarrowedByRowAccess(User $user, string $objectTypeId): bool
    {
        if (!$this->rowAccess->isEnabled()) {
            return false;
        }

        return in_array($objectTypeId, $this->teamAccessRules->resolveFor($user)->objectTypeIds(), true);
    }

    /**
     * @param  list<string>  $roleIds
     * @param  Collection<string, FieldPermission>  $restrictions
     * @param  Closure(FieldPermission): bool  $allows
     */
    private function anyRoleMay(array $roleIds, Collection $restrictions, Closure $allows): bool
    {
        if ($roleIds === []) {
            return $restrictions->every($allows);
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
