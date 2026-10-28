<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Engine\RecordBackfillStrategyInterface;
use App\Enums\Audit\ActorType;
use App\Enums\Engine\RecordBackfillKind;
use App\Enums\Engine\SystemFilterField;
use App\Enums\Timeline\ReminderEventState;
use App\Models\Attachment;
use App\Models\AuditEntry;
use App\Models\CustomRecord;
use App\Models\RecordNote;
use App\Models\ReminderTask;
use App\Models\TimelineEntry;
use App\Support\CustomFields\EncryptedFieldKeys;
use App\Support\Timeline\AdditionalTimelineProjection;
use App\Support\Timeline\AttachmentTimelineWriter;
use App\Support\Timeline\ChangeTimelineWriter;
use App\Support\Timeline\NoteTimelineWriter;
use App\Support\Timeline\ReminderTimelineWriter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class TimelineProjectionBackfillStrategy implements RecordBackfillStrategyInterface
{
    public function __construct(
        private readonly ChangeTimelineWriter $changeWriter,
        private readonly ReminderTimelineWriter $reminderWriter,
        private readonly AttachmentTimelineWriter $attachmentWriter,
        private readonly NoteTimelineWriter $noteWriter,
        private readonly AdditionalTimelineProjection $additionalProjection,
        private readonly EncryptedFieldKeys $encryptedFieldKeys,
    ) {}

    public function kind(): RecordBackfillKind
    {
        return RecordBackfillKind::TimelineProjection;
    }

    public function batchSize(): int
    {
        return max(1, (int) config('engine.backfill.timeline_batch_size'));
    }

    /**
     * @param  Builder<CustomRecord>  $query
     */
    public function constrainQuery(Builder $query): void {}

    /**
     * @param  Collection<int, CustomRecord>  $records
     */
    public function applyBatch(string $tenantId, Collection $records): int
    {
        $first = $records->first();

        if (!$first instanceof CustomRecord) {
            return 0;
        }

        $existing = $this->existingKeys($tenantId, $records);
        $encryptedKeys = $this->encryptedFieldKeys->forObjectType($first->object_type_id);

        $errorCount = 0;

        foreach ($records as $record) {
            try {
                $this->projectChanges($tenantId, $record, $existing, $encryptedKeys);
                $this->projectReminders($tenantId, $record, $existing);
                $this->projectAttachments($tenantId, $record, $existing);
                $this->projectNotes($tenantId, $record, $existing);
                $this->additionalProjection->project($tenantId, $record, $existing);
            } catch (Throwable $throwable) {
                $errorCount++;

                Log::warning('The timeline projection of a record could not be backfilled.', [
                    'tenant_id' => $tenantId,
                    'record_id' => (string) $record->getKey(),
                    'exception' => $throwable::class,
                    'reason' => $throwable->getMessage(),
                ]);
            }
        }

        return $errorCount;
    }

    /**
     * @param  Collection<int, CustomRecord>  $records
     * @return array<string, bool>
     */
    private function existingKeys(string $tenantId, Collection $records): array
    {
        $recordIds = $records->map(static fn (CustomRecord $record): string => (string) $record->getKey())->all();

        $keys = [];

        $rows = TimelineEntry::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('record_id', $recordIds)
            ->whereIn('source_key', $this->sourceKeys())
            ->get(['record_id', 'source_key', 'source_id', 'occurred_at']);

        foreach ($rows as $row) {
            $keys[$this->key(
                $row->record_id,
                $row->source_key,
                $row->source_id === null ? null : $row->source_id,
                $row->occurred_at,
            )] = true;
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function sourceKeys(): array
    {
        return array_map(strval(...), array_keys(config('timeline.sources', [])));
    }

    private function key(string $recordId, string $sourceKey, ?string $sourceId, DateTimeInterface $occurredAt): string
    {
        $moment = CarbonImmutable::instance($occurredAt)->setTimezone('UTC')->format('Y-m-d H:i:s.u');

        return "{$recordId}|{$sourceKey}|{$sourceId}|{$moment}";
    }

    /**
     * @param  array<string, bool>  $existing
     */
    private function isProjected(array $existing, CustomRecord $record, string $sourceKey, ?string $sourceId, DateTimeInterface $occurredAt): bool
    {
        return isset($existing[$this->key((string) $record->getKey(), $sourceKey, $sourceId, $occurredAt)]);
    }

    /**
     * @param  array<string, bool>  $existing
     * @param  array<int, string>  $encryptedKeys
     */
    private function projectChanges(string $tenantId, CustomRecord $record, array $existing, array $encryptedKeys): void
    {
        $entries = AuditEntry::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', $record->getKey())
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();

        $changes = [];

        foreach ($entries as $entry) {
            $sourceKey = $entry->field_key === SystemFilterField::Stage->value ? 'stage_change' : 'field_change';
            $sourceId = (string) $entry->getKey();

            if ($this->isProjected($existing, $record, $sourceKey, $sourceId, $entry->changed_at)) {
                continue;
            }

            $changes[] = [
                'source_id' => $sourceId,
                'occurred_at' => $entry->changed_at,
                'field_key' => $entry->field_key,
                'old' => $entry->old_value,
                'new' => $entry->new_value,
                'actor_id' => $entry->actor_id,
                'actor_type' => $entry->actor_type,
            ];
        }

        $this->changeWriter->record($record, $changes, $encryptedKeys);
    }

    /**
     * @param  array<string, bool>  $existing
     */
    private function projectReminders(string $tenantId, CustomRecord $record, array $existing): void
    {
        $reminders = ReminderTask::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('record_id', $record->getKey())
            ->with('assignee')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($reminders as $reminder) {
            $moments = [
                [ReminderEventState::Created, $reminder->created_at],
                [ReminderEventState::Due, $reminder->notified_at],
                [ReminderEventState::Completed, $reminder->done_at],
            ];

            foreach ($moments as [$state, $occurredAt]) {
                if (!$occurredAt instanceof DateTimeInterface) {
                    continue;
                }

                if ($this->isProjected($existing, $record, 'reminder', (string) $reminder->getKey(), $occurredAt)) {
                    continue;
                }

                $this->reminderWriter->record(
                    $reminder,
                    $state,
                    $occurredAt,
                    $reminder->creator_id,
                    ActorType::User,
                );
            }
        }
    }

    /**
     * @param  array<string, bool>  $existing
     */
    private function projectAttachments(string $tenantId, CustomRecord $record, array $existing): void
    {
        $attachments = Attachment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('record_id', $record->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $items = [];

        foreach ($attachments as $attachment) {
            $occurredAt = $attachment->created_at;

            if (!$occurredAt instanceof DateTimeInterface) {
                continue;
            }

            $sourceKey = $this->attachmentWriter->sourceKey($attachment);

            if ($this->isProjected($existing, $record, $sourceKey, (string) $attachment->getKey(), $occurredAt)) {
                continue;
            }

            $items[] = [
                'attachment' => $attachment,
                'actor_id' => $attachment->uploaded_by === null ? null : $attachment->uploaded_by,
                'actor_type' => $attachment->uploaded_by === null ? null : ActorType::User,
            ];
        }

        $this->attachmentWriter->record($record, $items);
    }

    /**
     * @param  array<string, bool>  $existing
     */
    private function projectNotes(string $tenantId, CustomRecord $record, array $existing): void
    {
        $notes = RecordNote::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('record_id', $record->getKey())
            ->with('author')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $items = [];

        foreach ($notes as $note) {
            $occurredAt = $note->created_at;

            if (!$occurredAt instanceof DateTimeInterface) {
                continue;
            }

            if ($this->isProjected($existing, $record, 'note', (string) $note->getKey(), $occurredAt)) {
                continue;
            }

            $items[] = [
                'note' => $note,
                'author_name' => $note->author?->name,
                'actor_id' => $note->author_id === null ? null : $note->author_id,
                'actor_type' => $note->author_id === null ? null : ActorType::User,
            ];
        }

        $this->noteWriter->record($record, $items);
    }
}
