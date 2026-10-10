<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\NotificationInbox;
use Illuminate\Support\Carbon;

class MarkInboxReadAction
{
    public function execute(NotificationInbox $inbox): NotificationInbox
    {
        $inbox->update(['read_at' => Carbon::now()]);

        return $inbox;
    }
}
