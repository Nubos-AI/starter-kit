<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\NotificationInbox;

class DeleteInboxItemAction
{
    public function execute(NotificationInbox $inbox): void
    {
        $inbox->delete();
    }
}
