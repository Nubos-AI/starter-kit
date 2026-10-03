<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteReminderTaskAction
{
    public function __construct(
        private readonly ReminderTimelineWriter $timelineWriter,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(ReminderTask $reminder): ReminderTask
    {
        return DB::transaction(function () use ($reminder): ReminderTask {
            if ($reminder->done_at === null) {
                $doneAt = Carbon::now();

                $reminder->forceFill(['done_at' => $doneAt])->save();

                $this->timelineWriter->record($reminder, ReminderEventState::Completed, $doneAt);
            }

            return $reminder->refresh();
        });
    }
}
