<?php

declare(strict_types=1);

namespace App\Contracts\Recurrence;

use App\DTOs\Recurrence\RecurrenceRule;
use Carbon\CarbonInterface;

interface RecurringInterface
{
    public function recurrenceRule(): ?RecurrenceRule;

    public function seriesStart(): ?CarbonInterface;

    public function seriesAnchor(): ?CarbonInterface;

    /**
     * @return array{recurrence_frequency: string|null, recurrence_interval: int|null, recurrence_ends_at: string|null}
     */
    public function recurrenceAttributes(): array;

    /**
     * @return array{series_id: string, series_occurrence_at: CarbonInterface, recurrence_frequency: null, recurrence_interval: null, recurrence_ends_at: null, recurrence_exceptions: null}
     */
    public function detachedAttributes(CarbonInterface $occurrenceAt): array;
}
