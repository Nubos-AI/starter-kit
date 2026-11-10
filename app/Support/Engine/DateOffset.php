<?php

declare(strict_types=1);

namespace App\Support\Engine;

use Carbon\CarbonImmutable;

class DateOffset
{
    public function add(CarbonImmutable $date, int $value, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'week' => $date->addWeeks($value),
            'month' => $date->addMonths($value),
            'hour' => $date->addHours($value),
            default => $date->addDays($value),
        };
    }

    public function sub(CarbonImmutable $date, int $value, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'week' => $date->subWeeks($value),
            'month' => $date->subMonths($value),
            'hour' => $date->subHours($value),
            default => $date->subDays($value),
        };
    }
}
