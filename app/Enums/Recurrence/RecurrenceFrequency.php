<?php

declare(strict_types=1);

namespace App\Enums\Recurrence;

enum RecurrenceFrequency: string
{
    case Daily = 'daily';

    case Weekly = 'weekly';

    case Monthly = 'monthly';

    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => __('i18n.backend.enums.recurrence.recurrence_frequency.daily'),
            self::Weekly => __('i18n.backend.enums.recurrence.recurrence_frequency.weekly'),
            self::Monthly => __('i18n.backend.enums.recurrence.recurrence_frequency.monthly'),
            self::Yearly => __('i18n.backend.enums.recurrence.recurrence_frequency.yearly'),
        };
    }
}
