<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Enums\Audit\ActorType;
use App\Enums\Timeline\ReminderEventState;
use App\Models\CustomRecord;
use App\Models\ReminderTask;
use DateTimeInterface;

class ReminderTimelineWriter
{
    public function __construct(
        private readonly TimelineRecorder $recorder,
    ) {}

    public function record(
        ReminderTask $reminder,
        ReminderEventState $state,
        DateTimeInterface $occurredAt,
        ?string $actorId = null,
        ?ActorType $actorType = null,
    ): void {
        if ($reminder->record_id === null) {
            return;
        }

        $record = CustomRecord::query()->whereKey($reminder->record_id)->first();

        if (!$record instanceof CustomRecord) {
            return;
        }

        $entry = [
            'source_id' => (string) $reminder->getKey(),
            'occurred_at' => $occurredAt,
            'payload' => [
                'state' => $state->value,
                'subject' => $reminder->subject,
                'dueAt' => $reminder->due_at?->toISOString(),
                'assigneeId' => $reminder->assignee_id,
                'assigneeName' => $reminder->assignee?->name,
            ],
        ];

        if ($actorId !== null || $actorType !== null) {
            $entry['actor_id'] = $actorId;
            $entry['actor_type'] = $actorType?->value;
        }

        $this->recorder->record($record, 'reminder', [$entry]);
    }
}
