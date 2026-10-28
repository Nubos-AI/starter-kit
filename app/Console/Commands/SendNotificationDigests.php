<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\DigestFrequency;
use App\Models\NotificationDigestState;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\DigestRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests';

    protected $description = 'Run per-user notification digests when the local send window is due';

    public function handle(DigestRunner $runner, MaintenanceLockRegistry $maintenanceLocks): int
    {
        $now = CarbonImmutable::now();
        $fallbackTimezone = (string) config('app.timezone');
        $skippedLocked = 0;
        $lockedTenantIds = array_flip($maintenanceLocks->lockedTenantIds());

        $states = NotificationDigestState::withoutTenantScope()
            ->whereNotNull('frequency')
            ->with('user')
            ->cursor();

        foreach ($states as $state) {
            if ($state->user === null) {
                continue;
            }

            $local = $now->setTimezone($state->user->timezone ?? $fallbackTimezone);

            if ($local->hour !== $state->hour || $local->minute >= 15) {
                continue;
            }

            if ($state->frequency === DigestFrequency::Weekly && !$local->isMonday()) {
                continue;
            }

            if ($this->alreadySentThisCycle($state, $now)) {
                continue;
            }

            if (isset($lockedTenantIds[$state->tenant_id])) {
                $skippedLocked++;

                continue;
            }

            $runner->run($state->tenant_id, $state->user_id);
        }

        return self::SUCCESS;
    }

    private function alreadySentThisCycle(NotificationDigestState $state, CarbonImmutable $now): bool
    {
        if ($state->last_digest_sent_at === null) {
            return false;
        }

        $cycleStart = $state->frequency === DigestFrequency::Weekly
            ? $now->subDays(6)
            : $now->subHours(23);

        return $state->last_digest_sent_at->greaterThan($cycleStart);
    }
}
