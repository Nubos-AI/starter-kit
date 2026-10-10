<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\NotificationRule;

class DeleteNotificationRuleAction
{
    public function execute(NotificationRule $rule): void
    {
        $rule->delete();
    }
}
