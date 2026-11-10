<?php

declare(strict_types=1);

namespace App\Enums\Formulas;

enum BackfillStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    case Failed = 'failed';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
        ));
    }
}
