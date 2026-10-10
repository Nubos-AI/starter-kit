<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Http\Resources\RecordActivityResource;
use App\Models\CustomRecord;
use App\Models\RecordActivity;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Traits\Timeline\GuardsTimelineVisibility;
use Illuminate\Support\Collection;

class ActivityTimelineSource implements TimelineSourceInterface
{
    use GuardsTimelineVisibility;

    public function key(): string
    {
        return 'activity';
    }

    public function modelClass(): string
    {
        return RecordActivity::class;
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

        $activities = RecordActivity::query()->where('record_id', $record->id)
            ->whereIn('id', $entries->pluck('source_id'))->with(['activityType', 'assignee'])->get()->keyBy('id');

        return $entries->filter(fn (TimelineEntry $entry): bool => $activities->has($entry->source_id))
            ->each(function (TimelineEntry $entry) use ($activities): void {
                $entry->payload = RecordActivityResource::make($activities->get($entry->source_id))->resolve();
            })->values();
    }
}
