<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ReminderType;

class DeleteReminderTypeAction
{
    public function execute(ReminderType $reminderType): void
    {
        $reminderType->delete();
    }
}
