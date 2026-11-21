<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Models\MaintenanceLock;

class EmergencyReleaseMaintenanceLock extends ReleaseMaintenanceLock
{
    protected $signature = 'maintenance:emergency-unlock {tenant : ULID of the locked tenant} {--as-user= : E-mail address or ULID of a super admin authority} {--reason= : Reason for the emergency release (required, at most 2000 characters)}';

    protected $description = 'Releases the maintenance lock of a tenant through the emergency exit';

    protected function releaseMode(): MaintenanceLockRelease
    {
        return MaintenanceLockRelease::Emergency;
    }

    protected function justification(): ?string
    {
        return $this->textOption('reason');
    }

    protected function failureLogMessage(): string
    {
        return 'Emergency maintenance lock release command failed.';
    }

    protected function reportReleased(MaintenanceLock $released, string $tenantId): void
    {
        $label = MaintenanceLockRelease::Emergency->label();

        $this->warn("Maintenance lock {$released->getKey()} of tenant {$tenantId} was released ({$label}).");
    }
}
