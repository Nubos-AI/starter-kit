<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\DigestFrequency;
use App\Models\NotificationDigestState;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\DigestRunner;
use Carbon\CarbonImmutable;
use Carbon\WeekDay;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests';

    protected $description = 'Run per-user notification digests when the local send window is due';

    public function handle(DigestRunner $runner, MaintenanceLockRegistry $maintenanceLocks): int
    {
        $now = CarbonImmutable::now();
        $fallbackTimezone = (string) config('app.timezone');
        $skippedLocked = 0;
        $failedCount = 0;
        $lockedTenantIds = array_flip($maintenanceLocks->lockedTenantIds());

        $states = NotificationDigestState::withoutTenantScope()
            ->whereNotNull('frequency')
            ->whereHas('user', static fn (Builder $query): Builder => $query->active())
            ->with('user')
            ->cursor();

        foreach ($states as $state) {
            if ($state->user === null) {
                continue;
            }

            $local = $now->setTimezone($state->user->timezone ?? $fallbackTimezone);

            $cycleStart = $this->latestSendSlot($state, $local);

            if ($this->alreadySentThisCycle($state, $cycleStart)) {
                continue;
            }

            if (isset($lockedTenantIds[$state->tenant_id])) {
                $skippedLocked++;

                continue;
            }

            try {
                $runner->run($state->tenant_id, $state->user_id, $cycleStart);
            } catch (Throwable $exception) {
                $failedCount++;

                Log::error('Notification digest failed; the run continues with the next user.', [
                    'digest_state_id' => $state->getKey(),
                    'tenant_id' => $state->tenant_id,
                    'user_id' => $state->user_id,
                    'exception' => $exception,
                ]);
            }
        }

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function latestSendSlot(NotificationDigestState $state, CarbonImmutable $local): CarbonImmutable
    {
        $day = $state->frequency === DigestFrequency::Weekly ? $local->startOfWeek(WeekDay::Monday->value) : $local;
        $slot = $this->firstOccurrenceOf($day, $state->hour);

        if ($slot->lessThanOrEqualTo($local)) {
            return $slot;
        }

        return $this->firstOccurrenceOf($state->frequency === DigestFrequency::Weekly ? $day->subWeek() : $day->subDay(), $state->hour);
    }

    private function firstOccurrenceOf(CarbonImmutable $day, int $hour): CarbonImmutable
    {
        $slot = $day->setTime($hour, 0);
        $hourEarlier = $slot->subHour();

        return $hourEarlier->hour === $hour ? $hourEarlier : $slot;
    }

    private function alreadySentThisCycle(NotificationDigestState $state, CarbonImmutable $slot): bool
    {
        $lastReachedAt = $state->last_digest_sent_at ?? $state->updated_at;

        return $lastReachedAt !== null && $lastReachedAt->greaterThanOrEqualTo($slot);
    }
}
