<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Exceptions\StaleRecordException;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordValidator;
use App\Support\Modules\RecordExtensions;
use App\Support\Watchers\WatcherAutoSubscriber;
use App\Traits\Engine\GuardsOwnerAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class UpdateRecordAction
{
    use GuardsOwnerAssignment;

    public function __construct(
        private readonly ObjectTypeRegistry $objectTypes,
        private readonly RecordValidator $recordValidator,
        private readonly AuditRecorder $auditRecorder,
        private readonly WatcherAutoSubscriber $watcherAutoSubscriber,
        private readonly ComputedFieldWriter $computedFieldWriter,
        private readonly RecordExtensions $extensions,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(CustomRecord $record, array $input): CustomRecord
    {
        $validated = Validator::make($input, [
            'version' => ['required', 'integer'],
            'owner_id' => ['nullable', 'string', $this->ownerRule($record->tenant_id)],
            'team_id' => ['nullable', 'string', Rule::exists('teams', 'id')->where('tenant_id', $record->tenant_id)],
            'external_reference_id' => ['nullable', 'string'],
            'data' => ['nullable', 'array'],
        ])->validate();

        $expectedVersion = (int) $validated['version'];
        unset($validated['version']);

        $actingUser = Auth::user();
        $actingUser = $actingUser instanceof User ? $actingUser : null;

        $ownerChanged = array_key_exists('owner_id', $validated)
            && $validated['owner_id'] !== $record->owner_id;

        if ($ownerChanged) {
            $this->guardOwnerAssignment($actingUser);
        }

        $mergedData = null;
        /** @var list<string> $changedFieldKeys */
        $changedFieldKeys = [];

        if (array_key_exists('data', $validated) && is_array($validated['data'])) {
            $objectType = $this->objectTypes->forRecord($record);
            $inputData = $validated['data'];
            $changedFieldKeys = array_map(strval(...), array_keys($inputData));

            $mergedData = array_merge($record->data ?? [], $inputData);

            $this->guardWritableFields($record, $inputData, $actingUser);
            $this->recordValidator->validate($objectType, $mergedData, $record->tenant_id, $record);
        }

        $nextVersion = $expectedVersion + 1;

        return DB::transaction(function () use ($record, $validated, $mergedData, $changedFieldKeys, $expectedVersion, $nextVersion, $ownerChanged, $actingUser): CustomRecord {
            $values = $this->updateValues($validated, $mergedData, $nextVersion);
            $affected = CustomRecord::query()
                ->where('id', $record->getKey())
                ->where('version', $expectedVersion)
                ->update($values);

            if ($affected === 0) {
                throw new StaleRecordException;
            }

            if ($ownerChanged && $actingUser !== null) {
                $this->watcherAutoSubscriber->onOwnerAssigned($record->getKey(), $validated['owner_id']);
            }

            [$old, $new] = $this->auditStates($record, $validated, $mergedData);
            $this->auditRecorder->record($record, $old, $new, $nextVersion);

            $record->refresh();
            $this->extensions->saved($record);
            $this->computedFieldWriter->materialize($record, $changedFieldKeys);
            $record->refresh();

            DB::afterCommit(static fn () => $record->syncMakeSearchable($record->newCollection([$record])));

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $inputData
     */
    private function guardWritableFields(CustomRecord $record, array $inputData, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        $forbiddenKeys = FieldVisibilityResolver::forRequest()
            ->forbiddenWriteFieldKeys($user, $record->object_type_id);

        $attempted = array_intersect(array_keys($inputData), $forbiddenKeys);

        if ($attempted !== []) {
            throw new AuthorizationException(
                __('i18n.backend.actions.engine.update_record_action.you_lack_write_permission_for_these_fields').implode(', ', $attempted).'.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>|null  $mergedData
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function auditStates(CustomRecord $record, array $validated, ?array $mergedData): array
    {
        $oldData = $record->data ?? [];
        $newData = $mergedData ?? $oldData;

        $old = array_merge($oldData, [
            'owner_id' => $record->owner_id,
            'team_id' => $record->team_id,
            'external_reference_id' => $record->external_reference_id,
        ]);

        $new = array_merge($newData, [
            'owner_id' => array_key_exists('owner_id', $validated)
                ? $validated['owner_id']
                : $record->owner_id,
            'team_id' => array_key_exists('team_id', $validated)
                ? $validated['team_id']
                : $record->team_id,
            'external_reference_id' => array_key_exists('external_reference_id', $validated)
                ? $validated['external_reference_id']
                : $record->external_reference_id,
        ]);

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>|null  $mergedData
     * @return array<string, mixed>
     */
    private function updateValues(array $validated, ?array $mergedData, int $nextVersion): array
    {
        $values = ['version' => $nextVersion];

        if (array_key_exists('owner_id', $validated)) {
            $values['owner_id'] = $validated['owner_id'];
        }

        if (array_key_exists('team_id', $validated)) {
            $values['team_id'] = $validated['team_id'];
        }

        if (array_key_exists('external_reference_id', $validated)) {
            $values['external_reference_id'] = $validated['external_reference_id'];
        }

        if ($mergedData !== null) {
            $values['data'] = json_encode($mergedData);
        }

        return $values;
    }
}
