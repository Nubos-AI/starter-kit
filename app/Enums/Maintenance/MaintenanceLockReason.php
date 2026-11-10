<?php

declare(strict_types=1);

namespace App\Enums\Maintenance;

enum MaintenanceLockReason: string
{
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('i18n.backend.enums.maintenance.maintenance_lock_reason.enabled_manually'),
        };
    }
}
