<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Models\CustomRecord;
use App\Models\RecordMerge;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Traits\Timeline\GuardsTimelineVisibility;
use Illuminate\Support\Collection;

class MergeTimelineSource implements TimelineSourceInterface
{
    use GuardsTimelineVisibility;

    public function key(): string
    {
        return 'merge';
    }

    public function modelClass(): string
    {
        return RecordMerge::class;
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    public function filterVisible(User $user, CustomRecord $record, Collection $entries): Collection
    {
        if ($this->hiddenFrom($user, $record, $entries)) {
            return $this->noEntries($entries);
        }

        return $this->withHiddenCounterpartsRedacted($entries->values());
    }
}
