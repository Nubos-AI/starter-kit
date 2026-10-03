<?php

declare(strict_types=1);

namespace App\Support\Segments;

use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ManageableSegmentResolver
{
    public function resolveAuthorized(User $actor, string $segmentId, string $ability): Segment
    {
        $segment = Segment::query()
            ->where('tenant_id', $actor->tenant_id)
            ->whereKey($segmentId)
            ->firstOrFail();

        Gate::forUser($actor)->authorize($ability, $segment);

        return $segment;
    }
}
