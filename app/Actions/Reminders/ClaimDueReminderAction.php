<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\DTOs\Reminders\DueReminderClaim;
use App\Enums\Users\UserStatus;
use App\Models\ReminderTask;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use Illuminate\Support\Carbon;

class ClaimDueReminderAction
{
    public function execute(string $reminderId): DueReminderClaim
    {
        $reminder = ReminderTask::query()->with('assignee')->whereKey($reminderId)->first();

        if (!$reminder instanceof ReminderTask || !$this->isDue($reminder)) {
            return DueReminderClaim::unclaimed();
        }

        $claimedAt = Carbon::now()->startOfSecond();

        $claimed = ReminderTask::query()
            ->whereKey($reminderId)
            ->whereNull('notified_at')
            ->update(['notified_at' => $claimedAt]);

        if ($claimed === 0 || !$reminder->assignee instanceof User) {
            return DueReminderClaim::unclaimed();
        }

        $pending = array_values(array_diff(
            (new ReminderDueNotification($reminder))->via($reminder->assignee),
            $reminder->notified_channels ?? [],
        ));

        return new DueReminderClaim(true, $claimedAt->toIso8601ZuluString(), $pending);
    }

    private function isDue(ReminderTask $reminder): bool
    {
        return $reminder->done_at === null
            && $reminder->notified_at === null
            && $reminder->due_at !== null
            && $reminder->due_at->lessThanOrEqualTo(Carbon::now())
            && $reminder->assignee instanceof User
            && $reminder->assignee->status === UserStatus::Accepted;
    }
}
