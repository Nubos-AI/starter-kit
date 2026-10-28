<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum RuleTriggerType: string
{
    case DateBased = 'date_based';

    case StageChange = 'stage_change';

    case Assignment = 'assignment';

    case FieldChange = 'field_change';

    case Creation = 'creation';

    public function isEventBased(): bool
    {
        return $this !== self::DateBased;
    }

    /**
     * @return list<self>
     */
    public static function eventTypes(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isEventBased()));
    }
}
