<?php

declare(strict_types=1);

namespace App\Enums\Maintenance;

enum MaintenanceLockStatus: string
{
    case Active = 'active';

    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('i18n.backend.enums.maintenance.maintenance_lock_status.active'),
            self::Released => __('i18n.backend.enums.maintenance.maintenance_lock_status.released'),
        };
    }
}
