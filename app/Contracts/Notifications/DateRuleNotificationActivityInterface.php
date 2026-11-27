<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

use App\DTOs\Notifications\DateRuleCase;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'notifications.date-rule.')]
interface DateRuleNotificationActivityInterface
{
    #[ActivityMethod(name: 'deliverRuleNotification')]
    public function deliverRuleNotification(DateRuleCase $case, string $recipientId): bool;

    #[ActivityMethod(name: 'createRuleFollowUpReminder')]
    public function createRuleFollowUpReminder(DateRuleCase $case): void;
}
