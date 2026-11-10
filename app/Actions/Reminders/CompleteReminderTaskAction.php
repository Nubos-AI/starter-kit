<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\DTOs\Recurrence\RecurrenceRule;
use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Support\Recurrence\RecurrenceExpander;
use App\Support\Timeline\ReminderTimelineWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteReminderTaskAction
{
    public function __construct(
        private readonly ReminderTimelineWriter $timelineWriter,
        private readonly RecurrenceExpander $recurrenceExpander,
        private readonly CopyReminderOccurrenceAction $copyReminderOccurrence,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(ReminderTask $reminder): ReminderTask
    {
        return DB::transaction(function () use ($reminder): ReminderTask {
            $locked = ReminderTask::query()->whereKey($reminder->getKey())->lockForUpdate()->first();

            if ($locked instanceof ReminderTask && $locked->done_at === null) {
                $doneAt = Carbon::now();

                $locked->forceFill(['done_at' => $doneAt])->save();

                $this->timelineWriter->record($locked, ReminderEventState::Completed, $doneAt);

                $this->openNextOccurrence($locked);
            }

            return $reminder->refresh();
        });
    }

    private function openNextOccurrence(ReminderTask $reminder): void
    {
        $rule = $reminder->recurrenceRule();
        $nextDueAt = $rule instanceof RecurrenceRule && $reminder->due_at !== null
            ? $this->recurrenceExpander->after($reminder->seriesAnchor() ?? $reminder->due_at, $rule, $reminder->due_at)
            : null;

        if (!$rule instanceof RecurrenceRule || !$nextDueAt instanceof CarbonImmutable) {
            return;
        }

        $exceptionsAhead = array_values(array_filter(
            $rule->exceptions,
            static fn (string $exception): bool => CarbonImmutable::parse($exception)->greaterThan($nextDueAt),
        ));

        $this->copyReminderOccurrence->execute($reminder, [
            'due_at' => $nextDueAt,
            ...$reminder->recurrenceAttributes(),
            'recurrence_anchor_at' => $reminder->seriesAnchor(),
            'recurrence_exceptions' => $exceptionsAhead === [] ? null : $exceptionsAhead,
        ]);
    }
}
