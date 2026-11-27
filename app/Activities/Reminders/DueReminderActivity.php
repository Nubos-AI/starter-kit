<?php

declare(strict_types=1);

namespace App\Activities\Reminders;

use App\Actions\Reminders\ClaimDueReminderAction;
use App\Actions\Reminders\CompleteDueReminderAction;
use App\Actions\Reminders\DeliverDueReminderChannelAction;
use App\Actions\Reminders\ReleaseDueReminderClaimAction;
use App\Contracts\Reminders\DueReminderActivityInterface;
use App\DTOs\Reminders\DueReminderCase;
use App\DTOs\Reminders\DueReminderClaim;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;

class DueReminderActivity implements DueReminderActivityInterface
{
    public function __construct(
        private readonly ClaimDueReminderAction $claimDueReminder,
        private readonly DeliverDueReminderChannelAction $deliverChannel,
        private readonly CompleteDueReminderAction $completeDueReminder,
        private readonly ReleaseDueReminderClaimAction $releaseClaim,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function claimDueReminder(DueReminderCase $case): DueReminderClaim
    {
        return $this->steps->run('reminders.claim-due', $this->contextOf($case), fn (): DueReminderClaim => $this->tenantBinder->run(
            $case->tenantId,
            fn (): DueReminderClaim => $this->claimDueReminder->execute($case->reminderId),
        ));
    }

    public function deliverDueReminderChannel(DueReminderCase $case, string $channel): void
    {
        $this->steps->run('reminders.deliver-channel', [...$this->contextOf($case), 'channel' => $channel], fn () => $this->tenantBinder->run(
            $case->tenantId,
            fn () => $this->deliverChannel->execute($case->reminderId, $channel),
        ));
    }

    public function completeDueReminder(DueReminderCase $case, string $claimedAt): void
    {
        $this->steps->run('reminders.complete-due', $this->contextOf($case), fn () => $this->tenantBinder->run(
            $case->tenantId,
            fn () => $this->completeDueReminder->execute($case->reminderId, $claimedAt),
        ));
    }

    public function releaseDueReminderClaim(DueReminderCase $case, string $claimedAt): void
    {
        $this->steps->run('reminders.release-claim', $this->contextOf($case), fn () => $this->tenantBinder->run(
            $case->tenantId,
            fn () => $this->releaseClaim->execute($case->reminderId, $claimedAt),
        ));
    }

    /**
     * @return array{tenant_id: string, reminder_id: string}
     */
    private function contextOf(DueReminderCase $case): array
    {
        return ['tenant_id' => $case->tenantId, 'reminder_id' => $case->reminderId];
    }
}
