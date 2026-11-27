<?php

declare(strict_types=1);

namespace App\Contracts\Reminders;

use App\DTOs\Reminders\DueReminderCase;
use App\DTOs\Reminders\DueReminderClaim;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'reminders.')]
interface DueReminderActivityInterface
{
    #[ActivityMethod(name: 'claimDueReminder')]
    public function claimDueReminder(DueReminderCase $case): DueReminderClaim;

    #[ActivityMethod(name: 'deliverDueReminderChannel')]
    public function deliverDueReminderChannel(DueReminderCase $case, string $channel): void;

    #[ActivityMethod(name: 'completeDueReminder')]
    public function completeDueReminder(DueReminderCase $case, string $claimedAt): void;

    #[ActivityMethod(name: 'releaseDueReminderClaim')]
    public function releaseDueReminderClaim(DueReminderCase $case, string $claimedAt): void;
}
