<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Support\Collection;

class TimelineEntryAccessResolver
{
    /** @param Collection<int, TimelineEntry> $entries */
    public function stampAccess(User $user, Collection $entries): void {}
}
