<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\DTOs\Notifications\NotificationDigestCase;
use App\Enums\Notifications\DigestFrequency;
use App\Models\NotificationDigestState;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Carbon\CarbonImmutable;
use Carbon\WeekDay;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;

class ListDueNotificationDigestsAction
{
    public function __construct(
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly Repository $config,
    ) {}

    /**
     * @return list<NotificationDigestCase>
     */
    public function execute(CarbonImmutable $now): array
    {
        $lockedTenantIds = $this->maintenanceLocks->lockedTenantIds();
        $fallbackTimezone = (string) $this->config->get('app.timezone');
        $limit = max(1, (int) $this->config->get('record-processing.scans.max_cases_per_run'));
        $cases = [];

        $states = NotificationDigestState::withoutTenantScope()
            ->whereNotNull('frequency')
            ->whereHas('user', static fn (Builder $query): Builder => $query->active())
            ->when($lockedTenantIds !== [], static fn (Builder $query): Builder => $query->whereNotIn('notification_digest_states.tenant_id', $lockedTenantIds))
            ->with('user')
            ->cursor();

        foreach ($states as $state) {
            if ($state->user === null) {
                continue;
            }

            $cycleStart = $this->latestSendSlot($state, $now->setTimezone($state->user->timezone ?? $fallbackTimezone));

            if ($this->alreadySentThisCycle($state, $cycleStart)) {
                continue;
            }

            $cases[] = new NotificationDigestCase($state->tenant_id, $state->user_id, $cycleStart->getTimestamp());

            if (count($cases) >= $limit) {
                break;
            }
        }

        return $cases;
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
