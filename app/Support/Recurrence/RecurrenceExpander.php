<?php

declare(strict_types=1);

namespace App\Support\Recurrence;

use App\DTOs\Recurrence\RecurrenceRule;
use App\Enums\Recurrence\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class RecurrenceExpander
{
    /**
     * @return list<CarbonImmutable>
     */
    public function between(CarbonInterface $start, RecurrenceRule $rule, CarbonInterface $from, CarbonInterface $to): array
    {
        $anchor = $this->local($start);
        $limit = (int) config('recurrence.max_occurrences');
        $index = $this->indexBefore($anchor, $rule, $this->local($from));
        $occurrences = [];

        while (count($occurrences) < $limit) {
            $occurrence = $this->nth($anchor, $rule, $index);

            if (!$occurrence->lessThan($to) || $this->isPastEnd($occurrence, $rule)) {
                break;
            }

            if ($occurrence->greaterThanOrEqualTo($from) && !$rule->excludes($occurrence)) {
                $occurrences[] = $occurrence;
            }

            $index++;
        }

        return $occurrences;
    }

    public function after(CarbonInterface $anchor, RecurrenceRule $rule, CarbonInterface $occurrence): ?CarbonImmutable
    {
        $localAnchor = $this->local($anchor);
        $index = $this->indexBefore($localAnchor, $rule, $this->local($occurrence));
        $limit = $index + (int) config('recurrence.max_occurrences');

        for (; $index <= $limit; $index++) {
            $next = $this->nth($localAnchor, $rule, $index);

            if ($this->isPastEnd($next, $rule)) {
                return null;
            }

            if ($next->greaterThan($occurrence) && !$rule->excludes($next)) {
                return $next;
            }
        }

        return null;
    }

    public function isOccurrence(CarbonInterface $start, RecurrenceRule $rule, CarbonInterface $moment): bool
    {
        $occurrences = $this->between($start, $rule, $moment, $moment->toImmutable()->addSecond());

        return $occurrences !== [] && $occurrences[0]->equalTo($moment);
    }

    private function nth(CarbonImmutable $anchor, RecurrenceRule $rule, int $index): CarbonImmutable
    {
        $steps = $index * $rule->interval;

        $occurrence = match ($rule->frequency) {
            RecurrenceFrequency::Daily => $anchor->addDays($steps),
            RecurrenceFrequency::Weekly => $anchor->addWeeks($steps),
            RecurrenceFrequency::Monthly => $anchor->addMonthsNoOverflow($steps),
            RecurrenceFrequency::Yearly => $anchor->addYearsNoOverflow($steps),
        };

        return $occurrence->utc();
    }

    private function indexBefore(CarbonImmutable $anchor, RecurrenceRule $rule, CarbonImmutable $from): int
    {
        if (!$from->greaterThan($anchor)) {
            return 0;
        }

        $elapsed = match ($rule->frequency) {
            RecurrenceFrequency::Daily => $anchor->diffInDays($from),
            RecurrenceFrequency::Weekly => $anchor->diffInWeeks($from),
            RecurrenceFrequency::Monthly => $anchor->diffInMonths($from),
            RecurrenceFrequency::Yearly => $anchor->diffInYears($from),
        };

        return max(0, (int) floor($elapsed / $rule->interval) - 1);
    }

    private function isPastEnd(CarbonImmutable $occurrence, RecurrenceRule $rule): bool
    {
        return $rule->endsAt instanceof CarbonImmutable && $occurrence->greaterThan($rule->endsAt);
    }

    private function local(CarbonInterface $moment): CarbonImmutable
    {
        return $moment->toImmutable()->setTimezone((string) config('reports.timezone'));
    }
}
