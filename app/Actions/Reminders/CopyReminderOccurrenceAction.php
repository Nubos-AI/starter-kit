<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Support\Carbon;

class CopyReminderOccurrenceAction
{
    public function __construct(private readonly ReminderTimelineWriter $timelineWriter) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(ReminderTask $series, array $attributes): ReminderTask
    {
        $copy = ReminderTask::query()->create([
            'tenant_id' => $series->tenant_id,
            'owner_id' => $series->owner_id,
            'creator_id' => $series->creator_id,
            'assignee_id' => $series->assignee_id,
            'record_id' => $series->record_id,
            'reminder_type_id' => $series->reminder_type_id,
            'source_type' => $series->source_type,
            'source_id' => $series->source_id,
            'subject' => $series->subject,
            'note' => $series->note,
            ...$attributes,
        ]);

        $this->timelineWriter->record($copy, ReminderEventState::Created, $copy->created_at ?? Carbon::now());

        return $copy;
    }
}
