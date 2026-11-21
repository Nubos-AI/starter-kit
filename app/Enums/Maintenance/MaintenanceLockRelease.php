<?php

declare(strict_types=1);

namespace App\Enums\Maintenance;

enum MaintenanceLockRelease: string
{
    case Automatic = 'automatic';

    case Manual = 'manual';

    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => __('i18n.backend.enums.maintenance.maintenance_lock_release.automatic'),
            self::Manual => __('i18n.backend.enums.maintenance.maintenance_lock_release.manual'),
            self::Emergency => __('i18n.backend.enums.maintenance.maintenance_lock_release.emergency_release'),
        };
    }
}
