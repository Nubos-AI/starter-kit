<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\NotificationInbox;

class MarkInboxUnreadAction
{
    public function execute(NotificationInbox $inbox): NotificationInbox
    {
        $inbox->update(['read_at' => null]);

        return $inbox;
    }
}
