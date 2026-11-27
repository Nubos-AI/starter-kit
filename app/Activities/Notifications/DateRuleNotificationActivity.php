<?php

declare(strict_types=1);

namespace App\Activities\Notifications;

use App\Actions\Notifications\CreateRuleFollowUpReminderAction;
use App\Actions\Notifications\DeliverRuleNotificationAction;
use App\Contracts\Notifications\DateRuleNotificationActivityInterface;
use App\DTOs\Notifications\DateRuleCase;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\User;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;

class DateRuleNotificationActivity implements DateRuleNotificationActivityInterface
{
    public function __construct(
        private readonly DeliverRuleNotificationAction $deliverRuleNotification,
        private readonly CreateRuleFollowUpReminderAction $createFollowUpReminder,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function deliverRuleNotification(DateRuleCase $case, string $recipientId): bool
    {
        return $this->steps->run('notifications.date-rule.deliver', [...$this->contextOf($case), 'user_id' => $recipientId], fn (): bool => $this->tenantBinder->run(
            $case->tenantId,
            fn (): bool => $this->deliverRuleNotification->execute(
                $this->rule($case),
                $this->record($case),
                User::query()->where('tenant_id', $case->tenantId)->whereKey($recipientId)->firstOrFail(),
                $case->stage,
            ),
        ));
    }

    public function createRuleFollowUpReminder(DateRuleCase $case): void
    {
        $this->steps->run('notifications.date-rule.follow-up-reminder', $this->contextOf($case), fn () => $this->tenantBinder->run(
            $case->tenantId,
            fn () => $this->createFollowUpReminder->execute($this->rule($case), $this->record($case), $case->stage),
        ));
    }

    private function rule(DateRuleCase $case): NotificationRule
    {
        return NotificationRule::query()->whereKey($case->ruleId)->firstOrFail();
    }

    private function record(DateRuleCase $case): CustomRecord
    {
        return CustomRecord::query()->whereKey($case->recordId)->firstOrFail();
    }

    /**
     * @return array{tenant_id: string, rule_id: string, record_id: string, stage: string}
     */
    private function contextOf(DateRuleCase $case): array
    {
        return ['tenant_id' => $case->tenantId, 'rule_id' => $case->ruleId, 'record_id' => $case->recordId, 'stage' => $case->stage];
    }
}
