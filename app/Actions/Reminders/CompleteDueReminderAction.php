<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteDueReminderAction
{
    public function __construct(private readonly ReminderTimelineWriter $timelineWriter) {}

    /**
     * @throws Throwable
     */
    public function execute(string $reminderId, string $claimedAt): void
    {
        DB::transaction(function () use ($reminderId, $claimedAt): void {
            $reminder = ReminderTask::query()->whereKey($reminderId)->firstOrFail();

            $this->timelineWriter->record($reminder, ReminderEventState::Due, Carbon::parse($claimedAt));

            ReminderTask::query()->whereKey($reminderId)->update(['notified_channels' => null]);
        });
    }
}
