<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

use App\DTOs\Engine\RecordChangeBatch;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'NotificationRules.')]
interface EvaluateNotificationRulesActivityInterface
{
    #[ActivityMethod(name: 'evaluateRules')]
    public function evaluateRules(RecordChangeBatch $changes): int;
}
