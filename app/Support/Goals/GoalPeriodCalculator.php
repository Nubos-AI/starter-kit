<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Enums\Goals\GoalPeriodType;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class GoalPeriodCalculator
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function boundsFor(GoalPeriodType $periodType, DateTimeInterface $moment): array
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->zone());

        $start = match ($periodType) {
            GoalPeriodType::Month => $local->startOfMonth(),
            GoalPeriodType::Quarter => $local->startOfQuarter(),
            GoalPeriodType::Year => $local->startOfYear(),
        };

        $end = match ($periodType) {
            GoalPeriodType::Month => $start->addMonth(),
            GoalPeriodType::Quarter => $start->addQuarter(),
            GoalPeriodType::Year => $start->addYear(),
        };

        return [
            'start' => $start->setTimezone('UTC'),
            'end' => $end->setTimezone('UTC'),
        ];
    }

    private function zone(): string
    {
        return (string) config('reports.timezone');
    }
}
