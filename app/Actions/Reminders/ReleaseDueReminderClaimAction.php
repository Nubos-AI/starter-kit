<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Models\ReminderTask;
use Illuminate\Support\Carbon;

class ReleaseDueReminderClaimAction
{
    public function execute(string $reminderId, string $claimedAt): void
    {
        ReminderTask::query()
            ->whereKey($reminderId)
            ->where('notified_at', Carbon::parse($claimedAt))
            ->update(['notified_at' => null]);
    }
}
