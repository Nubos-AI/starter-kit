<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Models\ReminderTask;

class DeleteReminderTaskAction
{
    public function execute(ReminderTask $reminder): void
    {
        $reminder->delete();
    }
}
