<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\AuditEntry;
use App\Models\CustomRecord;
use App\Models\OutboxEvent;
use App\Support\Approvals\ApprovalInvalidator;
use App\Support\Audit\ActorResolver;
use App\Support\CustomFields\EncryptedFieldKeys;
use App\Support\Timeline\ChangeTimelineWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

class AuditRecorder
{
    public function __construct(
        private readonly ActorResolver $actor,
        private readonly RecordDiff $diff,
        private readonly RecordChangeRelayStarter $relayStarter,
        private readonly ChangeTimelineWriter $changeTimelineWriter,
        private readonly EncryptedFieldKeys $encryptedFieldKeys,
        private readonly ApprovalInvalidator $approvalInvalidator,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws UnknownTimelineSourceException
     * @throws Throwable
     */
    public function record(Model $auditable, array $old, array $new, int $version): void
    {
        if (!$auditable instanceof CustomRecord) {
            throw new InvalidArgumentException(__('i18n.backend.support.engine.audit_recorder.audit_recorder_only_supports_auditing_custom_records'));
        }

        $record = $auditable;
        $objectTypeId = $record->object_type_id;
        $encryptedKeys = $this->encryptedFieldKeys->forObjectType($objectTypeId);
        $changes = $this->diff->between($old, $new, $encryptedKeys);

        if ($changes === []) {
            return;
        }

        $tenantId = $record->tenant_id;
        $recordId = (string) $record->getKey();
        [$actorId, $actorType] = $this->actor->resolve();
        $changedAt = now();

        $rows = [];
        $timelineChanges = [];

        foreach ($changes as $change) {
            $auditId = (string) Str::ulid();

            $rows[] = [
                'id' => $auditId,
                'tenant_id' => $tenantId,
                'auditable_type' => $record->getMorphClass(),
                'auditable_id' => $recordId,
                'field_key' => $change['field_key'],
                'old_value' => $this->encode($change['old']),
                'new_value' => $this->encode($change['new']),
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'version' => $version,
                'changed_at' => $changedAt,
            ];

            $timelineChanges[] = [
                'source_id' => $auditId,
                'occurred_at' => $changedAt,
                'field_key' => $change['field_key'],
                'old' => $change['old'],
                'new' => $change['new'],
                'actor_id' => $actorId,
                'actor_type' => $actorType,
            ];
        }

        AuditEntry::query()->insert($rows);

        $this->changeTimelineWriter->record($record, $timelineChanges, $encryptedKeys);

        $changedFieldKeys = array_map(
            static fn (array $change): string => $change['field_key'],
            $changes,
        );

        $this->approvalInvalidator->handleChange($record, $changedFieldKeys);

        $provenance = $this->automationProvenance();

        OutboxEvent::query()->create([
            'tenant_id' => $tenantId,
            'object_type_id' => $objectTypeId,
            'record_id' => $recordId,
            'triggered_by_automation_id' => $provenance['automation_id'],
            'root_run_id' => $provenance['root_run_id'],
            'version' => $version,
            'changed_field_keys' => $changedFieldKeys,
            'created_at' => $changedAt,
        ]);

        DB::afterCommit(fn () => $this->relayStarter->startNow());
    }

    /**
     * @return array{automation_id: string|null, root_run_id: string|null}
     */
    private function automationProvenance(): array
    {
        $marker = app()->bound('current_automation_actor') ? app('current_automation_actor') : null;

        if (!is_array($marker)) {
            return ['automation_id' => null, 'root_run_id' => null];
        }

        $automationId = $marker['id'] ?? null;
        $rootRunId = $marker['root_run_id'] ?? null;

        return [
            'automation_id' => is_string($automationId) && $automationId !== '' ? $automationId : null,
            'root_run_id' => is_string($rootRunId) && $rootRunId !== '' ? $rootRunId : null,
        ];
    }

    private function encode(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
