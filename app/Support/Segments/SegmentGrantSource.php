<?php

declare(strict_types=1);

namespace App\Support\Segments;

use App\Models\Segment;
use App\Models\SegmentShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SegmentGrantSource
{
    /**
     * @return EloquentCollection<int, SegmentShare>
     */
    public function forSegment(Segment $segment): EloquentCollection
    {
        return SegmentShare::query()
            ->where('segment_id', $segment->getKey())
            ->get();
    }

    public function holdsTeam(User $user, string $teamId): bool
    {
        return $user->teams()->whereKey($teamId)->exists();
    }
}
