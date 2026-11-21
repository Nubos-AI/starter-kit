<?php

declare(strict_types=1);

namespace App\Traits\Recurrence;

use App\DTOs\Recurrence\RecurrenceRule;
use Carbon\CarbonInterface;

trait HasRecurrenceRule
{
    public function recurrenceRule(): ?RecurrenceRule
    {
        return RecurrenceRule::fromAttributes(
            $this->recurrence_frequency,
            $this->recurrence_interval,
            $this->recurrence_ends_at,
            array_values(array_filter((array) $this->recurrence_exceptions, is_string(...))),
        );
    }

    /**
     * @return array{recurrence_frequency: string|null, recurrence_interval: int|null, recurrence_ends_at: string|null}
     */
    public function recurrenceAttributes(): array
    {
        return [
            'recurrence_frequency' => $this->recurrence_frequency?->value,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_ends_at' => $this->recurrence_ends_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{series_id: string, series_occurrence_at: CarbonInterface, recurrence_frequency: null, recurrence_interval: null, recurrence_ends_at: null, recurrence_exceptions: null}
     */
    public function detachedAttributes(CarbonInterface $occurrenceAt): array
    {
        return [
            'series_id' => (string) $this->getKey(),
            'series_occurrence_at' => $occurrenceAt->toImmutable()->utc(),
            'recurrence_frequency' => null,
            'recurrence_interval' => null,
            'recurrence_ends_at' => null,
            'recurrence_exceptions' => null,
        ];
    }
}
