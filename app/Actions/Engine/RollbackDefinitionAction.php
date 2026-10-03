<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Models\ConfigurationArtifactVersion;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionVersion;
use App\Models\ObjectType;
use App\Models\ObjectTypeDefinitionVersion;
use App\Models\User;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use App\Support\ConfigBundle\ArtifactWriteSequence;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class RollbackDefinitionAction
{
    public function __construct(
        private readonly ConfigBundleSerializer $serializer,
        private readonly SnapshotDefinitionAction $snapshot,
        private readonly TargetKeyResolver $targetKeys,
        private readonly ArtifactWriterRegistry $writers,
        private readonly ArtifactWriteSequence $writeSequence,
    ) {}

    /**
     * @return array{version: ObjectTypeDefinitionVersion, soft_deleted_field_keys: list<string>}
     *
     * @throws Throwable
     */
    public function execute(ObjectType $objectType, ObjectTypeDefinitionVersion $targetVersion): array
    {
        if ($targetVersion->object_type_id !== (string) $objectType->getKey()) {
            throw ValidationException::withMessages([
                'version' => __('i18n.backend.actions.engine.rollback_definition_action.the_selected_version_does_not_belong_to_this_object'),
            ]);
        }

        return DB::transaction(function () use ($objectType, $targetVersion): array {
            $this->snapshot->execute($objectType, __('i18n.backend.actions.engine.rollback_definition_action.automatic_snapshot_before_rollback'));

            $snapshot = $targetVersion->snapshot;
            /** @var array<string, mixed> $objectTypeSnapshot */
            $objectTypeSnapshot = $snapshot['object_type'] ?? [];
            /** @var array<int, array<string, mixed>> $fieldSnapshots */
            $fieldSnapshots = $snapshot['field_definitions'] ?? [];

            $restorableKeys = config('engine.definition_snapshot.object_type_restorable');
            $restorable = Arr::only($objectTypeSnapshot, is_array($restorableKeys) ? array_values(array_filter($restorableKeys, 'is_string')) : []);
            $objectType->fill($restorable);

            if ($objectType->isDirty()) {
                $objectType->save();
            }

            $targetKeys = $this->restoreFields($objectType, $fieldSnapshots);
            $softDeleted = $this->softDeleteAddedFields($objectType, $targetKeys);

            $summary = __('i18n.backend.actions.engine.rollback_definition_action.rollback_to_version').$targetVersion->version_number;

            if ($softDeleted !== []) {
                $summary .= ' (soft-deleted: '.implode(', ', $softDeleted).')';
            }

            $newVersion = $this->snapshot->execute($objectType, $summary);

            return [
                'version' => $newVersion,
                'soft_deleted_field_keys' => $softDeleted,
            ];
        });
    }

    /**
     * @param  list<ArtifactKind>  $coveredKinds
     * @return list<ArtifactWriteResult>
     *
     * @throws MissingActingUserException
     * @throws Throwable
     */
    public function rollbackGroup(User $actingUser, string $snapshotGroupId, array $coveredKinds = []): array
    {
        $plan = $this->planOf($actingUser, $snapshotGroupId, $coveredKinds);

        return DB::transaction(function () use ($actingUser, $plan): array {
            $results = $this->writeSequence->apply($this->writers, $actingUser, $plan['writes']);

            foreach ($plan['removals'] as $removal) {
                $results[] = $this->writers->for($removal['kind'])->remove($actingUser, $removal['kind'], $removal['key']);
            }

            foreach ($this->writers->all() as $writer) {
                $results = [...$results, ...$writer->flush($actingUser)];
            }

            return $results;
        });
    }

    /**
     * @param  list<ArtifactKind>  $coveredKinds
     * @return array{writes: list<array{kind: ArtifactKind, artifact: BundleArtifact, state: DiffState, rank: int}>, removals: list<array{kind: ArtifactKind, key: string, rank: int}>}
     *
     * @throws MissingActingUserException
     */
    private function planOf(User $actingUser, string $snapshotGroupId, array $coveredKinds): array
    {
        $ranks = $this->dependencyRanks();
        $writes = [];
        $covered = $this->explicitlyCoveredOf($coveredKinds);
        $restored = [];

        foreach ($this->groupRows($snapshotGroupId) as $row) {
            $kind = $row['kind'];
            $covered[$kind->value] = true;

            if ($row['artifact_id'] === null) {
                continue;
            }

            $restored[$kind->value][$row['artifact']->key] = true;

            $writes[] = [
                'kind' => $kind,
                'artifact' => $row['artifact'],
                'state' => $this->targetKeys->idFor($kind, $row['artifact']->key) === null ? DiffState::Added : DiffState::Modified,
                'rank' => $ranks[$kind->value] ?? count($ranks),
            ];
        }

        $removals = [];

        foreach ($this->currentArtifacts() as $artifact) {
            if (!isset($covered[$artifact->kind->value]) || isset($restored[$artifact->kind->value][$artifact->key])) {
                continue;
            }

            $removals[] = [
                'kind' => $artifact->kind,
                'key' => $artifact->key,
                'rank' => $ranks[$artifact->kind->value] ?? count($ranks),
            ];
        }

        usort($writes, static fn (array $left, array $right): int => $left['rank'] <=> $right['rank']);
        usort($removals, static fn (array $left, array $right): int => $right['rank'] <=> $left['rank']);

        return ['writes' => $writes, 'removals' => $removals];
    }

    /**
     * @param  list<ArtifactKind>  $coveredKinds
     * @return array<string, true>
     */
    private function explicitlyCoveredOf(array $coveredKinds): array
    {
        $covered = [];

        foreach ($coveredKinds as $kind) {
            $covered[$kind->value] = true;

            if ($kind === ArtifactKind::ObjectTypes || $kind === ArtifactKind::FieldDefinitions) {
                $covered[ArtifactKind::ObjectTypes->value] = true;
                $covered[ArtifactKind::FieldDefinitions->value] = true;
            }
        }

        return $covered;
    }

    /**
     * @return list<array{kind: ArtifactKind, artifact: BundleArtifact, artifact_id: string|null}>
     */
    private function groupRows(string $snapshotGroupId): array
    {
        $rows = [];

        foreach (ConfigurationArtifactVersion::query()->where('snapshot_group_id', $snapshotGroupId)->get() as $version) {
            $rows[] = [
                'kind' => $version->artifact_kind,
                'artifact' => new BundleArtifact($version->artifact_kind, $version->artifact_key, $version->snapshot),
                'artifact_id' => $version->artifact_id,
            ];
        }

        foreach (ObjectTypeDefinitionVersion::query()->where('snapshot_group_id', $snapshotGroupId)->get() as $version) {
            /** @var array<string, mixed> $payload */
            $payload = $version->snapshot['object_type'] ?? [];

            $rows[] = [
                'kind' => ArtifactKind::ObjectTypes,
                'artifact' => new BundleArtifact(ArtifactKind::ObjectTypes, $this->businessKeyOf($payload), $payload),
                'artifact_id' => $version->object_type_id,
            ];
        }

        foreach (FieldDefinitionVersion::query()->where('snapshot_group_id', $snapshotGroupId)->get() as $version) {
            $payload = $version->snapshot;

            $rows[] = [
                'kind' => ArtifactKind::FieldDefinitions,
                'artifact' => new BundleArtifact(ArtifactKind::FieldDefinitions, $this->businessKeyOf($payload), $payload),
                'artifact_id' => $version->field_definition_id,
            ];
        }

        return $rows;
    }

    /**
     * @return list<BundleArtifact>
     *
     * @throws MissingActingUserException
     */
    private function currentArtifacts(): array
    {
        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            throw new MissingActingUserException(
                __('i18n.backend.actions.engine.rollback_definition_action.a_rollback_can_only_be_performed_within_a_bound'),
                'target-tenant-not-bound',
            );
        }

        return $this->serializer->serialize($tenantId)->artifacts;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function businessKeyOf(array $payload): string
    {
        $key = $payload['key'] ?? null;

        if (!is_string($key) || $key === '') {
            throw new InvalidArgumentException(__('i18n.backend.actions.engine.rollback_definition_action.a_row_in_this_snapshot_group_has_no_business'));
        }

        return $key;
    }

    /**
     * @return array<string, int>
     */
    private function dependencyRanks(): array
    {
        $configured = config('engine.tenant_artifacts');
        $ranks = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            $kind = is_array($entry) ? ArtifactKind::tryFrom((string) ($entry['kind'] ?? '')) : null;

            if ($kind !== null && !isset($ranks[$kind->value])) {
                $ranks[$kind->value] = count($ranks);
            }
        }

        return $ranks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fieldSnapshots
     * @return list<string>
     */
    private function restoreFields(ObjectType $objectType, array $fieldSnapshots): array
    {
        $targetKeys = [];

        foreach ($fieldSnapshots as $fieldSnapshot) {
            $key = $fieldSnapshot['key'] ?? null;

            if (!is_string($key) || $key === '') {
                continue;
            }

            $targetKeys[] = $key;

            $existing = FieldDefinition::withTrashed()
                ->where('object_type_id', $objectType->getKey())
                ->where('key', $key)
                ->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $existing->fill(Arr::except($fieldSnapshot, ['key']));
                $existing->save();

                continue;
            }

            FieldDefinition::query()->create(array_merge($fieldSnapshot, [
                'object_type_id' => $objectType->getKey(),
            ]));
        }

        return $targetKeys;
    }

    /**
     * @param  list<string>  $targetKeys
     * @return list<string>
     */
    private function softDeleteAddedFields(ObjectType $objectType, array $targetKeys): array
    {
        $softDeleted = [];

        $current = FieldDefinition::query()
            ->where('object_type_id', $objectType->getKey())
            ->get();

        foreach ($current as $field) {
            if (!in_array($field->key, $targetKeys, true)) {
                $field->delete();
                $softDeleted[] = $field->key;
            }
        }

        return $softDeleted;
    }
}
