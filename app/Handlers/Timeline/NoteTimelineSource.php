<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Models\CustomRecord;
use App\Models\RecordNote;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Traits\Timeline\GuardsTimelineVisibility;
use Illuminate\Support\Collection;

class NoteTimelineSource implements TimelineSourceInterface
{
    use GuardsTimelineVisibility;

    public function key(): string
    {
        return 'note';
    }

    public function modelClass(): string
    {
        return RecordNote::class;
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

        $sourceIds = $entries->pluck('source_id')->filter()->unique()->values()->all();

        if ($sourceIds === []) {
            return $this->noEntries($entries);
        }

        /** @var Collection<string, string> $bodies */
        $bodies = RecordNote::query()->whereIn('id', $sourceIds)->pluck('body', 'id');

        return $entries
            ->filter(static fn (TimelineEntry $entry): bool => $entry->source_id !== null && $bodies->has($entry->source_id))
            ->each(static function (TimelineEntry $entry) use ($bodies): void {
                $payload = $entry->payload ?? [];
                $payload['body'] = $bodies->get((string) $entry->source_id);

                $entry->payload = $payload;
            })
            ->values();
    }
}
