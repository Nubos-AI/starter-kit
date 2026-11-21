<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use App\Support\Temporal\TemporalScheduleGateway;
use Illuminate\Support\Str;

class MaintenanceScheduleSuspender
{
    public function __construct(private readonly TemporalScheduleGateway $gateway) {}

    /**
     * @return list<non-empty-string>
     */
    public function suspendableIdsFor(string $tenantId): array
    {
        $scheduleIds = [];

        foreach ($this->gateway->pauseStates() as $scheduleId => $paused) {
            $scheduleId = $scheduleId;

            if ($paused || $scheduleId === '' || !Str::contains($scheduleId, $tenantId)) {
                continue;
            }

            $scheduleIds[] = $scheduleId;
        }

        return $scheduleIds;
    }

    /**
     * @param  list<string>  $scheduleIds
     * @param  non-empty-string  $note
     */
    public function suspend(array $scheduleIds, string $note): void
    {
        foreach ($scheduleIds as $scheduleId) {
            if ($scheduleId !== '') {
                $this->gateway->pause($scheduleId, $note);
            }
        }
    }

    /**
     * @param  list<string>  $scheduleIds
     * @param  non-empty-string  $note
     */
    public function resume(array $scheduleIds, string $note): void
    {
        foreach ($scheduleIds as $scheduleId) {
            if ($scheduleId !== '') {
                $this->gateway->unpause($scheduleId, $note);
            }
        }
    }
}
