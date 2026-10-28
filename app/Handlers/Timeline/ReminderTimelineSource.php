<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Models\CustomRecord;
use App\Models\ReminderTask;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Traits\Timeline\GuardsTimelineVisibility;
use Illuminate\Support\Collection;

class ReminderTimelineSource implements TimelineSourceInterface
{
    use GuardsTimelineVisibility;

    public function key(): string
    {
        return 'reminder';
    }

    public function modelClass(): string
    {
        return ReminderTask::class;
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

        $reminders = ReminderTask::query()
            ->whereIn('id', $sourceIds)
            ->get(['id', 'subject', 'due_at', 'done_at'])
            ->keyBy('id');

        return $entries
            ->filter(static fn (TimelineEntry $entry): bool => $entry->source_id !== null && $reminders->has($entry->source_id))
            ->each(function (TimelineEntry $entry) use ($reminders): void {
                /** @var ReminderTask $reminder */
                $reminder = $reminders->get((string) $entry->source_id);

                $payload = $entry->payload ?? [];
                $payload['subject'] = $reminder->subject;
                $payload['dueAt'] = $reminder->due_at?->toISOString();
                $payload['overdue'] = $this->isOverdue($reminder);

                $entry->payload = $payload;
            })
            ->values();
    }

    private function isOverdue(ReminderTask $reminder): bool
    {
        return $reminder->due_at !== null && $reminder->done_at === null && $reminder->due_at->isPast();
    }
}
