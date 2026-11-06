<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Models\ApprovalEvent;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Support\Collection;

class ApprovalTimelineSource implements TimelineSourceInterface
{
    public function key(): string
    {
        return 'approval';
    }

    public function modelClass(): string
    {
        return ApprovalEvent::class;
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    public function filterVisible(User $user, CustomRecord $record, Collection $entries): Collection
    {
        return $entries;
    }
}
