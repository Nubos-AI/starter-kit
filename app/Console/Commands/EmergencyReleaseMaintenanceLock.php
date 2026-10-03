<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Models\MaintenanceLock;

class EmergencyReleaseMaintenanceLock extends ReleaseMaintenanceLock
{
    protected $signature = 'maintenance:emergency-unlock {tenant : ULID des gesperrten Mandanten} {--as-user= : E-Mail-Adresse oder ULID einer SuperAdmin-Autorität} {--reason= : Grund der Notaufhebung (Pflicht, höchstens 2000 Zeichen)}';

    protected $description = 'Hebt die Wartungssperre eines Mandanten über den Notausgang auf';

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

        $this->warn("Die Wartungssperre {$released->getKey()} des Mandanten {$tenantId} wurde aufgehoben ({$label}).");
    }
}
