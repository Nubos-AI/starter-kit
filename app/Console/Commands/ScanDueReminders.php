<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ScanDueReminders extends Command
{
    protected $signature = 'reminders:scan-due';

    protected $description = 'Notify assignees of due, open, un-notified reminder tasks across all tenants';

    public function handle(ReminderTimelineWriter $timelineWriter, MaintenanceLockRegistry $maintenanceLocks): int
    {
        $skippedLocked = 0;
        $lockedTenantIds = array_flip($maintenanceLocks->lockedTenantIds());

        $reminders = ReminderTask::withoutTenantScope()
            ->whereNull('done_at')
            ->whereNull('notified_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', Carbon::now())
            ->with('assignee')
            ->cursor();

        foreach ($reminders as $reminder) {
            if (!$reminder->assignee instanceof User) {
                continue;
            }

            if (isset($lockedTenantIds[$reminder->tenant_id])) {
                $skippedLocked++;

                continue;
            }

            $claimedAt = Carbon::now();

            $claimed = ReminderTask::withoutTenantScope()
                ->whereKey($reminder->getKey())
                ->whereNull('notified_at')
                ->update(['notified_at' => $claimedAt]);

            if ($claimed === 0) {
                continue;
            }

            TenantContext::withTenantId($reminder->tenant_id, function () use ($reminder, $timelineWriter, $claimedAt): void {
                $reminder->assignee->notify(new ReminderDueNotification($reminder));

                $timelineWriter->record($reminder, ReminderEventState::Due, $claimedAt);
            });
        }

        return self::SUCCESS;
    }
}
