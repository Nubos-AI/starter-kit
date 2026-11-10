<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\Segment;

class RuleSegmentSource
{
    public function find(string $segmentId): ?Segment
    {
        return Segment::query()->whereKey($segmentId)->first();
    }
}
