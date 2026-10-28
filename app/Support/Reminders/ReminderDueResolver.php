<?php

declare(strict_types=1);

namespace App\Support\Reminders;

use Carbon\CarbonImmutable;
use Throwable;

class ReminderDueResolver
{
    /**
     * @var list<string>
     */
    private array $units = ['minutes', 'hours', 'days', 'weeks', 'months'];

    /**
     * @param  array<string, mixed>  $due
     */
    public function resolve(array $due, CarbonImmutable $now): ?CarbonImmutable
    {
        return match ($due['mode'] ?? null) {
            'absolute' => $this->resolveAbsolute($due),
            'relative' => $this->resolveRelative($due, $now),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $due
     */
    private function resolveAbsolute(array $due): ?CarbonImmutable
    {
        $at = $due['at'] ?? null;

        if (!is_string($at) || trim($at) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($at);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $due
     */
    private function resolveRelative(array $due, CarbonImmutable $now): ?CarbonImmutable
    {
        $offset = $due['offset'] ?? null;

        if (!is_array($offset)) {
            return null;
        }

        $value = $offset['value'] ?? null;
        $unit = $offset['unit'] ?? null;

        if (!is_numeric($value) || !is_string($unit) || !in_array($unit, $this->units, true)) {
            return null;
        }

        $amount = (int) $value;
        $direction = ($offset['direction'] ?? 'after') === 'before' ? -1 : 1;

        $resolved = $now->{'add'.ucfirst($unit)}($amount * $direction);

        return $this->applyTimeOfDay($resolved, $due['time_of_day'] ?? null);
    }

    private function applyTimeOfDay(CarbonImmutable $moment, mixed $timeOfDay): CarbonImmutable
    {
        if (!is_string($timeOfDay) || !preg_match('/^(\d{1,2}):(\d{2})$/', $timeOfDay, $matches)) {
            return $moment;
        }

        return $moment->setTime((int) $matches[1], (int) $matches[2]);
    }
}
