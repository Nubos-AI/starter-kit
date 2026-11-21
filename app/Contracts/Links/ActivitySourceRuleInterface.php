<?php

declare(strict_types=1);

namespace App\Contracts\Links;

use App\Models\CustomRecord;
use App\Models\User;

interface ActivitySourceRuleInterface
{
    public function activityRefusal(User $user, string $sourceId, ?CustomRecord $record): ?string;
}
