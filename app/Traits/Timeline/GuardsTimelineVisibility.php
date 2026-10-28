<?php

declare(strict_types=1);

namespace App\Traits\Timeline;

use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Scopes\TeamRecordAccessScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

trait GuardsTimelineVisibility
{
    /**
     * @param  Collection<int, TimelineEntry>  $entries
     */
    private function hiddenFrom(User $user, CustomRecord $record, Collection $entries): bool
    {
        return $entries->isEmpty() || Gate::forUser($user)->denies('view', $record);
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    private function noEntries(Collection $entries): Collection
    {
        return $entries->take(0)->values();
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    private function withHiddenCounterpartsRedacted(Collection $entries): Collection
    {
        $counterpartIds = $entries
            ->map(static fn (TimelineEntry $entry): mixed => $entry->payload['counterpart_id'] ?? null)
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($counterpartIds === []) {
            return $entries;
        }

        $visibleIds = CustomRecord::query()
            ->withTrashed()
            ->whereKey($counterpartIds)
            ->select('id');

        $hiddenIds = CustomRecord::query()
            ->withoutGlobalScope(TeamRecordAccessScope::class)
            ->withTrashed()
            ->whereKey($counterpartIds)
            ->whereNotIn('id', $visibleIds)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($hiddenIds === []) {
            return $entries;
        }

        return $entries->map(static function (TimelineEntry $entry) use ($hiddenIds): TimelineEntry {
            if (!in_array($entry->payload['counterpart_id'] ?? null, $hiddenIds, true)) {
                return $entry;
            }

            return (clone $entry)->forceFill([
                'payload' => [...$entry->payload, 'counterpart_id' => null, 'counterpart_number' => null],
            ]);
        })->values();
    }
}
