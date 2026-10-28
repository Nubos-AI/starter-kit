<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Models\ConfigurationArtifactVersion;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionVersion;
use App\Models\ObjectType;
use App\Models\ObjectTypeDefinitionVersion;
use App\Models\User;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class SnapshotDefinitionAction
{
    private int $roleKeyComponents = 2;

    public function __construct(
        private readonly ConfigBundleSerializer $serializer,
        private readonly TargetKeyResolver $targetKeys,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(ObjectType $objectType, ?string $changeSummary = null, ?string $snapshotGroupId = null): ObjectTypeDefinitionVersion
    {
        return DB::transaction(function () use ($objectType, $changeSummary, $snapshotGroupId): ObjectTypeDefinitionVersion {
            $fields = FieldDefinition::query()
                ->where('object_type_id', $objectType->getKey())
                ->orderBy('key')
                ->get();

            $versionNumber = (int) ObjectTypeDefinitionVersion::query()
                ->where('object_type_id', $objectType->getKey())
                ->max('version_number') + 1;

            $actorId = Auth::id();
            $changedAt = now();

            $version = ObjectTypeDefinitionVersion::query()->create([
                'object_type_id' => $objectType->getKey(),
                'snapshot_group_id' => $snapshotGroupId,
                'version_number' => $versionNumber,
                'snapshot' => $this->buildSnapshot($objectType, $fields),
                'actor_id' => $actorId !== null ? (string) $actorId : null,
                'change_summary' => $changeSummary,
                'changed_at' => $changedAt,
            ]);

            $fieldKeys = $this->configuredKeys('engine.definition_snapshot.field_keys');

            foreach ($fields as $field) {
                FieldDefinitionVersion::query()->create([
                    'object_type_definition_version_id' => $version->getKey(),
                    'object_type_id' => $objectType->getKey(),
                    'field_definition_id' => $field->getKey(),
                    'snapshot_group_id' => $snapshotGroupId,
                    'field_key' => $field->key,
                    'snapshot' => $field->only($fieldKeys),
                    'version_number' => $versionNumber,
                    'changed_at' => $changedAt,
                ]);
            }

            return $version;
        });
    }

    /**
     * @param  list<array{kind: ArtifactKind, model: Model}>  $artifacts
     *
     * @throws MissingActingUserException
     * @throws Throwable
     */
    public function executeForArtifacts(array $artifacts, string $snapshotGroupId, ?string $changeSummary = null): void
    {
        $current = $this->currentArtifacts();
        $actorId = Auth::id();

        DB::transaction(function () use ($artifacts, $current, $snapshotGroupId, $changeSummary, $actorId): void {
            $stamp = [
                'snapshot_group_id' => $snapshotGroupId,
                'actor_id' => $actorId !== null ? (string) $actorId : null,
                'change_summary' => $changeSummary,
                'changed_at' => now(),
            ];

            /** @var array<string, ObjectType> $objectTypes */
            $objectTypes = [];

            foreach ($artifacts as $entry) {
                $kind = $entry['kind'];
                $model = $entry['model'];

                if ($kind === ArtifactKind::ObjectTypes || $kind === ArtifactKind::FieldDefinitions) {
                    $objectType = $this->objectTypeOf($model);
                    $objectTypes[(string) $objectType->getKey()] = $objectType;

                    continue;
                }

                $this->writeArtifactVersion($kind, $model, $this->artifactOf($kind, $model, $current), $stamp);
            }

            foreach ($objectTypes as $objectType) {
                $this->writeDefinitionVersions($objectType, $current, $stamp);
            }
        });
    }

    /**
     * @return array<string, list<BundleArtifact>>
     *
     * @throws MissingActingUserException
     */
    private function currentArtifacts(): array
    {
        $actingUser = Auth::user();

        if (!$actingUser instanceof User) {
            throw new MissingActingUserException(
                __('i18n.backend.actions.engine.snapshot_definition_action.an_artifact_snapshot_can_only_be_captured_while_an'),
                'acting-user-not-authenticated',
            );
        }

        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            throw new MissingActingUserException(
                __('i18n.backend.actions.engine.snapshot_definition_action.an_artifact_snapshot_can_only_be_captured_within_a'),
                'target-tenant-not-bound',
            );
        }

        $indexed = [];

        foreach ($this->serializer->serialize($tenantId)->artifacts as $artifact) {
            $indexed[$artifact->kind->value][] = $artifact;
        }

        return $indexed;
    }

    /**
     * @param  array<string, list<BundleArtifact>>  $current
     */
    private function artifactOf(ArtifactKind $kind, Model $model, array $current): BundleArtifact
    {
        foreach ($current[$kind->value] ?? [] as $artifact) {
            if ($this->identifies($kind, $artifact->key, $model)) {
                return $artifact;
            }
        }

        throw new InvalidArgumentException(__('i18n.backend.actions.engine.snapshot_definition_action.the_named_row_of_artifact_kind_has_no_transferable', ['value1' => $kind->value]));
    }

    private function identifies(ArtifactKind $kind, string $key, Model $model): bool
    {
        if ($kind !== ArtifactKind::RolePermissions) {
            return $this->targetKeys->idFor($kind, $key) === (string) $model->getKey();
        }

        $components = explode(':', $key);
        $roleKey = implode(':', array_slice($components, 0, $this->roleKeyComponents));
        $permissionKey = implode(':', array_slice($components, $this->roleKeyComponents));

        return $this->targetKeys->idFor(ArtifactKind::Roles, $roleKey) === (string) $model->getAttribute('role_id')
            && $this->targetKeys->idFor('permissions', $permissionKey) === (string) $model->getAttribute('permission_id');
    }

    private function identifierOf(ArtifactKind $kind, Model $model): ?string
    {
        $identifier = $kind === ArtifactKind::RolePermissions
            ? $model->getAttribute('role_id')
            : $model->getKey();

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    private function objectTypeOf(Model $model): ObjectType
    {
        if ($model instanceof ObjectType) {
            return $model;
        }

        return ObjectType::query()->whereKey($model->getAttribute('object_type_id'))->firstOrFail();
    }

    /**
     * @param  array{snapshot_group_id: string, actor_id: string|null, change_summary: string|null, changed_at: CarbonImmutable}  $stamp
     */
    private function writeArtifactVersion(ArtifactKind $kind, Model $model, BundleArtifact $artifact, array $stamp): void
    {
        $versionNumber = (int) ConfigurationArtifactVersion::query()
            ->where('artifact_kind', $kind->value)
            ->where('artifact_key', $artifact->key)
            ->max('version_number') + 1;

        ConfigurationArtifactVersion::query()->create([
            'snapshot_group_id' => $stamp['snapshot_group_id'],
            'artifact_kind' => $kind,
            'artifact_key' => $artifact->key,
            'artifact_id' => $this->identifierOf($kind, $model),
            'version_number' => $versionNumber,
            'snapshot' => $artifact->payload,
            'actor_id' => $stamp['actor_id'],
            'change_summary' => $stamp['change_summary'],
            'changed_at' => $stamp['changed_at'],
        ]);
    }

    /**
     * @param  array<string, list<BundleArtifact>>  $current
     * @param  array{snapshot_group_id: string, actor_id: string|null, change_summary: string|null, changed_at: CarbonImmutable}  $stamp
     */
    private function writeDefinitionVersions(ObjectType $objectType, array $current, array $stamp): void
    {
        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderBy('key')
            ->get();

        $fieldPayloads = $fields
            ->map(fn (FieldDefinition $field): array => $this->artifactOf(ArtifactKind::FieldDefinitions, $field, $current)->payload)
            ->all();

        $versionNumber = (int) ObjectTypeDefinitionVersion::query()
            ->where('object_type_id', $objectType->getKey())
            ->max('version_number') + 1;

        $version = ObjectTypeDefinitionVersion::query()->create([
            'object_type_id' => $objectType->getKey(),
            'snapshot_group_id' => $stamp['snapshot_group_id'],
            'version_number' => $versionNumber,
            'snapshot' => [
                'object_type' => $this->artifactOf(ArtifactKind::ObjectTypes, $objectType, $current)->payload,
                'field_definitions' => $fieldPayloads,
            ],
            'actor_id' => $stamp['actor_id'],
            'change_summary' => $stamp['change_summary'],
            'changed_at' => $stamp['changed_at'],
        ]);

        foreach ($fields as $index => $field) {
            FieldDefinitionVersion::query()->create([
                'object_type_definition_version_id' => $version->getKey(),
                'object_type_id' => $objectType->getKey(),
                'field_definition_id' => $field->getKey(),
                'snapshot_group_id' => $stamp['snapshot_group_id'],
                'field_key' => $field->key,
                'snapshot' => $fieldPayloads[$index],
                'version_number' => $versionNumber,
                'changed_at' => $stamp['changed_at'],
            ]);
        }
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array<string, mixed>
     */
    private function buildSnapshot(ObjectType $objectType, Collection $fields): array
    {
        $fieldKeys = $this->configuredKeys('engine.definition_snapshot.field_keys');

        return [
            'object_type' => $objectType->only($this->configuredKeys('engine.definition_snapshot.object_type_keys')),
            'field_definitions' => $fields
                ->map(fn (FieldDefinition $field): array => $field->only($fieldKeys))
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function configuredKeys(string $configKey): array
    {
        $keys = config($configKey);

        if (!is_array($keys)) {
            return [];
        }

        return array_values(array_filter($keys, 'is_string'));
    }
}
