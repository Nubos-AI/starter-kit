<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\DTOs\Recurrence\RecurrenceRule;
use App\Enums\Recurrence\SeriesScope;
use App\Models\ReminderTask;
use App\Support\Recurrence\RecurrenceExpander;
use App\Support\Recurrence\RecurrenceRuleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeleteReminderTaskAction
{
    public function __construct(
        private readonly RecurrenceRuleValidator $recurrenceRuleValidator,
        private readonly RecurrenceExpander $recurrenceExpander,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(ReminderTask $reminder, array $input = []): void
    {
        $validated = Validator::make($input, $this->recurrenceRuleValidator->scopeRules())->validate();
        $occurrenceAt = $this->recurrenceRuleValidator->occurrenceOf($validated, $reminder);

        DB::transaction(function () use ($reminder, $validated, $occurrenceAt): void {
            if ($occurrenceAt instanceof CarbonImmutable) {
                $this->skipOccurrence($reminder, $occurrenceAt);

                return;
            }

            if (($validated['scope'] ?? null) === SeriesScope::Series->value) {
                $this->deleteRestOfSeries($reminder);
            }

            $reminder->delete();
        });
    }

    private function skipOccurrence(ReminderTask $reminder, CarbonImmutable $occurrenceAt): void
    {
        /** @var RecurrenceRule $rule */
        $rule = $reminder->recurrenceRule();

        if ($reminder->due_at === null || !$occurrenceAt->equalTo($reminder->due_at)) {
            $reminder->update(['recurrence_exceptions' => $rule->exceptionsWith($occurrenceAt)]);

            return;
        }

        $next = $this->recurrenceExpander->after($reminder->seriesAnchor() ?? $reminder->due_at, $rule, $reminder->due_at);

        if ($next instanceof CarbonImmutable) {
            $reminder->update(['due_at' => $next, 'notified_at' => null, 'notified_channels' => null]);

            return;
        }

        $reminder->delete();
    }

    private function deleteRestOfSeries(ReminderTask $reminder): void
    {
        $seriesId = $reminder->series_id ?? (string) $reminder->getKey();

        ReminderTask::query()
            ->whereNull('done_at')
            ->whereKeyNot($reminder->getKey())
            ->where(fn ($rows) => $rows->where('series_id', $seriesId)->orWhereKey($seriesId))
            ->get()
            ->each(static fn (ReminderTask $row): ?bool => $row->delete());
    }
}
