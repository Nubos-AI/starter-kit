<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\NotificationInbox;
use Illuminate\Support\Carbon;

class ArchiveInboxAction
{
    public function execute(NotificationInbox $inbox): NotificationInbox
    {
        $inbox->update(['archived_at' => Carbon::now()]);

        return $inbox;
    }
}
