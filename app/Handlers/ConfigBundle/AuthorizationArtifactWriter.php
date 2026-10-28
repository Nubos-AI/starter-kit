<?php

declare(strict_types=1);

namespace App\Handlers\ConfigBundle;

use App\Actions\Authorization\CreateRoleAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Actions\Authorization\SyncFieldPermissionsAction;
use App\Actions\Authorization\SyncRolePermissionsAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Actions\Teams\DeleteTeamRecordAccessRuleAction;
use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\Authorization\RoleScope;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Exceptions\ConfigBundle\UnsupportedArtifactKindException;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\User;
use App\Support\Authorization\RoleDeletionGuard;
use App\Support\ConfigBundle\BundleWriteTenant;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Tenancy\TenantContext;
use App\Traits\ConfigBundle\ReportsArtifactWriteResults;
use App\Traits\ConfigBundle\ResolvesArtifactTargets;
use Illuminate\Support\Facades\Auth;

class AuthorizationArtifactWriter implements ArtifactWriterInterface
{
    use ReportsArtifactWriteResults;
    use ResolvesArtifactTargets;

    private int $roleKeyComponents = 2;

    private int $tailKeyMinimumComponents = 2;

    private int $rolePermissionKeyComponents = 4;

    private int $accessRuleMinimumComponents = 2;

    private int $teamKeyComponents = 1;

    /**
     * @var array<string, array{added: list<string>, removed: list<string>}>
     */
    private array $collectedRolePermissions = [];

    /**
     * @var array<string, array<string, array{can_read: bool, can_write: bool}>>
     */
    private array $collectedFieldPermissions = [];

    public function __construct(
        private readonly BundleWriteTenant $writeTenant,
        private readonly CreateRoleAction $createRole,
        private readonly DeleteRoleAction $deleteRole,
        private readonly DeleteTeamRecordAccessRuleAction $deleteTeamRecordAccessRule,
        private readonly RoleDeletionGuard $roleDeletionGuard,
        private readonly SyncFieldPermissionsAction $syncFieldPermissions,
        private readonly SyncRolePermissionsAction $syncRolePermissions,
        private readonly TargetKeyResolver $targetKeys,
        private readonly UpdateRoleAction $updateRole,
        private readonly UpsertTeamRecordAccessRuleAction $upsertTeamRecordAccessRule,
    ) {}

    public function supports(ArtifactKind $kind): bool
    {
        return match ($kind) {
            ArtifactKind::Roles,
            ArtifactKind::RolePermissions,
            ArtifactKind::FieldPermissions,
            ArtifactKind::TeamRecordAccessRules => true,
            default => false,
        };
    }

    public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $this->assertActingContext($actingUser);
        $this->assertSupported($kind);

        if ($state !== DiffState::Added && $state !== DiffState::Modified) {
            return $this->skippedByState($kind, $artifact->key, $state);
        }

        return match ($kind) {
            ArtifactKind::Roles => $this->applyRole($actingUser, $artifact, $state),
            ArtifactKind::RolePermissions => $this->collectRolePermission($artifact, $state),
            ArtifactKind::FieldPermissions => $this->collectFieldPermission($artifact, $state),
            ArtifactKind::TeamRecordAccessRules => $this->applyTeamRecordAccessRule($actingUser, $artifact, $state),
            default => throw new UnsupportedArtifactKindException($kind),
        };
    }

    public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        $this->assertActingContext($actingUser);
        $this->assertSupported($kind);

        return match ($kind) {
            ArtifactKind::Roles => $this->removeRole($actingUser, $key),
            ArtifactKind::RolePermissions => $this->removeRolePermission($key),
            ArtifactKind::FieldPermissions => $this->removeFieldPermission($key),
            ArtifactKind::TeamRecordAccessRules => $this->removeTeamRecordAccessRule($key),
            default => throw new UnsupportedArtifactKindException($kind),
        };
    }

    /**
     * @return list<ArtifactWriteResult>
     */
    public function flush(User $actingUser): array
    {
        $this->assertActingContext($actingUser);

        try {
            /** @var list<ArtifactWriteResult> $results */
            $results = [];

            foreach ($this->orderedRoleKeys($actingUser, array_keys($this->collectedRolePermissions)) as $roleKey) {
                $results[] = $this->flushRolePermissions($roleKey);
            }

            foreach ($this->orderedRoleKeys($actingUser, array_keys($this->collectedFieldPermissions)) as $roleKey) {
                $results[] = $this->flushFieldPermissions($roleKey);
            }

            return $results;
        } finally {
            $this->collectedRolePermissions = [];
            $this->collectedFieldPermissions = [];
        }
    }

    private function assertActingContext(User $actingUser): void
    {
        $authenticated = Auth::user();

        if (!$authenticated instanceof User || (string) $authenticated->getKey() !== (string) $actingUser->getKey()) {
            throw new MissingActingUserException(
                __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.permission_artifacts_can_only_be_written_while_the_specified'),
                'acting-user-not-authenticated',
            );
        }

        $writeTenantId = $this->writeTenant->tenantIdFor($actingUser);

        if ($writeTenantId === null || TenantContext::currentId() !== $writeTenantId) {
            throw new MissingActingUserException(
                __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.permission_artifacts_can_only_be_written_to_the_bound'),
                'target-tenant-not-bound',
            );
        }
    }

    private function assertSupported(ArtifactKind $kind): void
    {
        if (!$this->supports($kind)) {
            throw new UnsupportedArtifactKindException($kind);
        }
    }

    private function applyRole(User $actingUser, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::Roles;
        $components = $this->componentsOf($artifact->key);

        if (count($components) !== $this->roleKeyComponents) {
            return $this->malformed($kind, $artifact->key);
        }

        $scope = RoleScope::tryFrom($components[0]);

        if ($scope === null) {
            return $this->skipped(
                $kind,
                $artifact->key,
                __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_scope_from_key_is_unknown_in_the_target', ['value1' => $components[0], 'value2' => $artifact->key]),
            );
        }

        $payload = $artifact->payload;
        $notes = ($payload['is_system'] ?? false) === true ? [$this->systemFlagNote()] : [];

        $input = [
            'name' => $components[1],
            'scope' => $scope->value,
            'authority' => $payload['authority'] ?? null,
            'grants_subteam_visibility' => (bool) ($payload['grants_subteam_visibility'] ?? false),
        ];

        $role = $this->roleOf($artifact->key);

        if (!$role instanceof Role) {
            if ($state === DiffState::Modified) {
                return $this->missingTarget($kind, $artifact->key);
            }

            $role = $this->createRole->execute($actingUser, $input);
            $this->targetKeys->invalidate($kind);

            return new ArtifactWriteResult($kind, $artifact->key, ArtifactWriteAction::Created, (string) $role->getKey(), $notes);
        }

        if ($state === DiffState::Added) {
            return $this->skipped(
                $kind,
                $artifact->key,
                __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_artifact_was_reported_as_new_but_the_target', ['value1' => $artifact->key]),
            );
        }

        $this->updateRole->execute($actingUser, $role, $input);
        $this->targetKeys->invalidate($kind);

        return new ArtifactWriteResult($kind, $artifact->key, ArtifactWriteAction::Updated, (string) $role->getKey(), $notes);
    }

    private function removeRole(User $actingUser, string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::Roles;
        $role = $this->roleOf($key);

        if (!$role instanceof Role) {
            return $this->missingTarget($kind, $key);
        }

        $assignedUsers = $this->roleDeletionGuard->assignedUserCount($role);

        if ($assignedUsers > 0) {
            return $this->skipped(
                $kind,
                $key,
                __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_role_still_has_assigned_users_in_the_target', ['value1' => $key, 'value2' => $assignedUsers]),
            );
        }

        $assignedTeams = $this->assignedTeamCount($role);
        $notes = $assignedTeams > 0
            ? [__('i18n.backend.handlers.config_bundle.authorization_artifact_writer.deleting_the_role_removes_assignments_from_teams_these_assignments', ['value1' => $key, 'value2' => $assignedTeams])]
            : [];

        $this->deleteRole->execute($actingUser, $role);

        unset($this->collectedRolePermissions[$key], $this->collectedFieldPermissions[$key]);
        $this->targetKeys->invalidate($kind);

        return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Removed, (string) $role->getKey(), $notes);
    }

    private function collectRolePermission(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::RolePermissions;
        $parts = $this->splitKey($artifact->key, $this->rolePermissionKeyComponents);

        if ($parts === null) {
            return $this->malformed($kind, $artifact->key);
        }

        $roleKey = $parts['holder'];
        $permissionKey = $parts['tail'];

        $roleId = $this->targetKeys->idFor(ArtifactKind::Roles, $roleKey);

        if ($roleId === null) {
            return $this->unresolvable($kind, $artifact->key, 'role_id', $roleKey);
        }

        $permissionId = $this->targetKeys->idFor('permissions', $permissionKey);

        if ($permissionId === null) {
            return $this->unresolvable($kind, $artifact->key, 'permission_id', $permissionKey);
        }

        $delta = $this->rolePermissionDelta($roleKey);
        $delta['added'][] = $permissionId;
        $delta['removed'] = array_values(array_diff($delta['removed'], [$permissionId]));
        $this->collectedRolePermissions[$roleKey] = $delta;

        return new ArtifactWriteResult(
            $kind,
            $artifact->key,
            $state === DiffState::Added ? ArtifactWriteAction::Created : ArtifactWriteAction::Updated,
            $roleId,
            [__('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_permission_will_be_assigned_to_role_together_with', ['value1' => $permissionKey, 'value2' => $roleKey])],
        );
    }

    private function removeRolePermission(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::RolePermissions;
        $parts = $this->splitKey($key, $this->rolePermissionKeyComponents);

        if ($parts === null) {
            return $this->malformed($kind, $key);
        }

        $roleKey = $parts['holder'];
        $permissionKey = $parts['tail'];

        $roleId = $this->targetKeys->idFor(ArtifactKind::Roles, $roleKey);

        if ($roleId === null) {
            return $this->unresolvable($kind, $key, 'role_id', $roleKey);
        }

        $permissionId = $this->targetKeys->idFor('permissions', $permissionKey);

        if ($permissionId === null) {
            return $this->unresolvable($kind, $key, 'permission_id', $permissionKey);
        }

        $delta = $this->rolePermissionDelta($roleKey);
        $delta['removed'][] = $permissionId;
        $delta['added'] = array_values(array_diff($delta['added'], [$permissionId]));
        $this->collectedRolePermissions[$roleKey] = $delta;

        return new ArtifactWriteResult(
            $kind,
            $key,
            ArtifactWriteAction::Removed,
            $roleId,
            [__('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_permission_will_be_revoked_from_role_together_with', ['value1' => $permissionKey, 'value2' => $roleKey])],
        );
    }

    private function collectFieldPermission(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::FieldPermissions;
        $parts = $this->splitKey($artifact->key);

        if ($parts === null) {
            return $this->malformed($kind, $artifact->key);
        }

        $roleKey = $parts['holder'];
        $fieldKey = $parts['tail'];

        $roleId = $this->targetKeys->idFor(ArtifactKind::Roles, $roleKey);

        if ($roleId === null) {
            return $this->unresolvable($kind, $artifact->key, 'role_id', $roleKey);
        }

        $fieldDefinitionId = $this->targetKeys->idFor(ArtifactKind::FieldDefinitions, $fieldKey);

        if ($fieldDefinitionId === null) {
            return $this->unresolvable($kind, $artifact->key, 'field_definition_id', $fieldKey);
        }

        $collected = $this->collectedFieldPermissions[$roleKey] ?? [];
        $collected[$fieldDefinitionId] = [
            'can_read' => (bool) ($artifact->payload['can_read'] ?? false),
            'can_write' => (bool) ($artifact->payload['can_write'] ?? false),
        ];
        $this->collectedFieldPermissions[$roleKey] = $collected;

        return new ArtifactWriteResult(
            $kind,
            $artifact->key,
            $state === DiffState::Added ? ArtifactWriteAction::Created : ArtifactWriteAction::Updated,
            $roleId,
            [__('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_field_permission_for_will_be_written_to_role', ['value1' => $fieldKey, 'value2' => $roleKey])],
        );
    }

    private function removeFieldPermission(string $key): ArtifactWriteResult
    {
        return $this->skipped(
            ArtifactKind::FieldPermissions,
            $key,
            __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_field_permission_cannot_be_revoked_through_a_bundle', ['value1' => $key]),
        );
    }

    private function applyTeamRecordAccessRule(User $actingUser, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::TeamRecordAccessRules;
        $team = $this->teamOf($artifact->key);
        $objectType = $this->accessRuleObjectTypeOf($artifact->key);

        if (!$team instanceof Team) {
            return $this->unresolvable($kind, $artifact->key, 'team_id', $artifact->key);
        }

        if (!$objectType instanceof ObjectType) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $payload = $artifact->payload;

        if (!is_array($payload['filter_definition'] ?? null)) {
            return $this->skipped(
                $kind,
                $artifact->key,
                __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_access_rule_has_no_filter_definition_no_rule', ['value1' => $artifact->key]),
            );
        }

        $input = [
            'filter_definition' => $payload['filter_definition'],
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ];

        if (is_string($payload['inheritance'] ?? null)) {
            $input['inheritance'] = $payload['inheritance'];
        }

        try {
            $rule = $this->upsertTeamRecordAccessRule->execute($actingUser, $team, $objectType, $input);
        } catch (InvalidFilterTreeException $invalidFilter) {
            return $this->skipped(
                $kind,
                $artifact->key,
                __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_access_rule_was_skipped_due_to_an_invalid', ['value1' => $artifact->key, 'value2' => $invalidFilter->getMessage()]),
            );
        }

        $this->targetKeys->invalidate($kind);

        return new ArtifactWriteResult(
            $kind,
            $artifact->key,
            $state === DiffState::Added ? ArtifactWriteAction::Created : ArtifactWriteAction::Updated,
            (string) $rule->getKey(),
        );
    }

    private function removeTeamRecordAccessRule(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::TeamRecordAccessRules;
        $team = $this->teamOf($key);
        $objectType = $this->accessRuleObjectTypeOf($key);

        if (!$team instanceof Team || !$objectType instanceof ObjectType) {
            return $this->missingTarget($kind, $key);
        }

        $rule = TeamRecordAccessRule::query()
            ->where('team_id', $team->getKey())
            ->where('object_type_id', $objectType->getKey())
            ->first();

        if (!$rule instanceof TeamRecordAccessRule) {
            return $this->missingTarget($kind, $key);
        }

        $this->deleteTeamRecordAccessRule->execute($team, $objectType);
        $this->targetKeys->invalidate($kind);

        return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Removed, (string) $rule->getKey());
    }

    private function flushRolePermissions(string $roleKey): ArtifactWriteResult
    {
        $kind = ArtifactKind::RolePermissions;
        $role = $this->roleOf($roleKey);

        if (!$role instanceof Role) {
            return $this->missingTarget($kind, $roleKey);
        }

        $delta = $this->rolePermissionDelta($roleKey);
        $held = array_map(
            static fn (mixed $id): string => (string) $id,
            $role->permissions()->pluck('permissions.id')->all(),
        );

        $target = array_values(array_unique([
            ...array_diff($held, $delta['removed']),
            ...array_diff($delta['added'], $delta['removed']),
        ]));

        $this->syncRolePermissions->execute($role, ['permission_ids' => $target]);
        $this->targetKeys->invalidate($kind);

        $granted = count($target);
        $added = count(array_diff($delta['added'], $held));
        $withdrawn = count(array_intersect($delta['removed'], $held));

        return new ArtifactWriteResult(
            $kind,
            $roleKey,
            ArtifactWriteAction::Updated,
            (string) $role->getKey(),
            [__('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_role_has_permissions_after_completion_were_added_and', ['value1' => $roleKey, 'value2' => $granted, 'value3' => $added, 'value4' => $withdrawn])],
        );
    }

    private function flushFieldPermissions(string $roleKey): ArtifactWriteResult
    {
        $kind = ArtifactKind::FieldPermissions;
        $role = $this->roleOf($roleKey);

        if (!$role instanceof Role) {
            return $this->missingTarget($kind, $roleKey);
        }

        /** @var list<array{field_definition_id: string, can_read: bool, can_write: bool}> $permissions */
        $permissions = [];

        foreach ($this->collectedFieldPermissions[$roleKey] ?? [] as $fieldDefinitionId => $flags) {
            $permissions[] = [
                'field_definition_id' => $fieldDefinitionId,
                'can_read' => $flags['can_read'],
                'can_write' => $flags['can_write'],
            ];
        }

        $this->syncFieldPermissions->execute($role, $permissions);
        $this->targetKeys->invalidate($kind);

        $written = count($permissions);

        return new ArtifactWriteResult(
            $kind,
            $roleKey,
            ArtifactWriteAction::Updated,
            (string) $role->getKey(),
            [__('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_role_will_receive_field_permissions_from_the_bundle', ['value1' => $roleKey, 'value2' => $written])],
        );
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<string>
     */
    private function orderedRoleKeys(User $actingUser, array $roleKeys): array
    {
        sort($roleKeys);

        $ownRoleIds = $actingUser->rolesFor()
            ->map(static fn (Role $role): string => (string) $role->getKey())
            ->all();

        /** @var list<string> $foreign */
        $foreign = [];
        /** @var list<string> $own */
        $own = [];

        foreach ($roleKeys as $roleKey) {
            $roleId = $this->targetKeys->idFor(ArtifactKind::Roles, $roleKey);

            if ($roleId !== null && in_array($roleId, $ownRoleIds, true)) {
                $own[] = $roleKey;

                continue;
            }

            $foreign[] = $roleKey;
        }

        return [...$foreign, ...$own];
    }

    /**
     * @return array{added: list<string>, removed: list<string>}
     */
    private function rolePermissionDelta(string $roleKey): array
    {
        return $this->collectedRolePermissions[$roleKey] ?? ['added' => [], 'removed' => []];
    }

    private function roleOf(string $roleKey): ?Role
    {
        $roleId = $this->targetKeys->idFor(ArtifactKind::Roles, $roleKey);

        return $roleId === null ? null : Role::query()->whereKey($roleId)->first();
    }

    private function teamOf(string $key): ?Team
    {
        $components = $this->componentsOf($key);

        if (count($components) < $this->accessRuleMinimumComponents) {
            return null;
        }

        $teamId = $this->targetKeys->idFor('teams', implode(':', array_slice($components, 0, $this->teamKeyComponents)));

        return $teamId === null ? null : Team::query()->whereKey($teamId)->first();
    }

    private function assignedTeamCount(Role $role): int
    {
        return RoleAssignment::query()
            ->where('role_id', $role->getKey())
            ->where('model_type', (new Team)->getMorphClass())
            ->distinct()
            ->count('model_id');
    }

    /**
     * @return list<string>
     */
    private function componentsOf(string $key): array
    {
        return explode(':', $key);
    }

    /**
     * @return array{holder: string, tail: string}|null
     */
    private function splitKey(string $key, ?int $expectedComponents = null): ?array
    {
        $components = $this->componentsOf($key);
        $count = count($components);
        $minimum = $this->roleKeyComponents + $this->tailKeyMinimumComponents;

        if ($count < $minimum || ($expectedComponents !== null && $count !== $expectedComponents)) {
            return null;
        }

        return [
            'holder' => implode(':', array_slice($components, 0, $this->roleKeyComponents)),
            'tail' => implode(':', array_slice($components, $this->roleKeyComponents)),
        ];
    }

    private function systemFlagNote(): string
    {
        return __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_bundle_s_is_system_field_is_not_transferred');
    }

    private function malformed(ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->skipped($kind, $key, __('i18n.backend.handlers.config_bundle.authorization_artifact_writer.the_key_does_not_have_the_expected_number_of', ['value1' => $key]));
    }

    private function accessRuleObjectTypeOf(string $key): ?ObjectType
    {
        $components = $this->componentsOf($key);

        if (count($components) < $this->accessRuleMinimumComponents) {
            return null;
        }

        $objectTypeId = $this->targetKeys->idFor(ArtifactKind::ObjectTypes, implode(':', array_slice($components, $this->teamKeyComponents)));

        return $objectTypeId === null ? null : ObjectType::query()->whereKey($objectTypeId)->first();
    }
}
