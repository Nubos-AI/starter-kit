<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\PendingChannelDelivery;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanDueReminders extends Command
{
    protected $signature = 'reminders:scan-due';

    protected $description = 'Notify assignees of due, open, un-notified reminder tasks across all tenants';

    public function handle(
        ReminderTimelineWriter $timelineWriter,
        MaintenanceLockRegistry $maintenanceLocks,
        PendingChannelDelivery $channelDelivery = new PendingChannelDelivery,
    ): int {
        $skippedLocked = 0;
        $failedCount = 0;
        $lockedTenantIds = array_flip($maintenanceLocks->lockedTenantIds());

        $reminders = ReminderTask::withoutTenantScope()
            ->whereNull('done_at')
            ->whereNull('notified_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', Carbon::now())
            ->whereHas('assignee', static fn (Builder $query): Builder => $query->active())
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

            try {
                TenantContext::withTenantId($reminder->tenant_id, function () use ($reminder, $channelDelivery): void {
                    $channelDelivery->deliver(
                        $reminder->assignee,
                        new ReminderDueNotification($reminder),
                        $reminder->notified_channels,
                        ReminderTask::withoutTenantScope()->whereKey($reminder->getKey()),
                    );
                });
            } catch (Throwable $exception) {
                $failedCount++;

                ReminderTask::withoutTenantScope()
                    ->whereKey($reminder->getKey())
                    ->where('notified_at', $claimedAt)
                    ->update(['notified_at' => null]);

                $this->logFailure('Due reminder notification failed; the claim is released so the next scan retries it.', $reminder, $exception);

                continue;
            }

            try {
                TenantContext::withTenantId($reminder->tenant_id, function () use ($reminder, $timelineWriter, $claimedAt): void {
                    $timelineWriter->record($reminder, ReminderEventState::Due, $claimedAt);
                });
            } catch (Throwable $exception) {
                $failedCount++;

                $this->logFailure('Due reminder timeline entry failed after the notification was delivered.', $reminder, $exception);
            }
        }

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function logFailure(string $message, ReminderTask $reminder, Throwable $exception): void
    {
        Log::error($message, [
            'reminder_id' => $reminder->getKey(),
            'tenant_id' => $reminder->tenant_id,
            'assignee_id' => $reminder->assignee_id,
            'exception' => $exception,
        ]);
    }
}
