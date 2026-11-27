<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ActivityType;

class DeleteActivityTypeAction
{
    public function execute(ActivityType $activityType): void
    {
        $activityType->delete();
    }
}
