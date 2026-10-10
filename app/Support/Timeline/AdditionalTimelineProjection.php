<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Models\CustomRecord;

class AdditionalTimelineProjection
{
    /** @param array<string, bool> $existing */
    public function project(string $tenantId, CustomRecord $record, array $existing): void {}
}
