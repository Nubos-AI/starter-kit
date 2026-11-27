<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\DTOs\Notifications\NotificationDigestClaim;
use App\Enums\Notifications\DigestFrequency;
use App\Models\NotificationDigestState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ClaimNotificationDigestCycleAction
{
    public function execute(string $userId, CarbonImmutable $cycleStart): NotificationDigestClaim
    {
        $state = NotificationDigestState::query()->where('user_id', $userId)->first();

        if (!$state instanceof NotificationDigestState) {
            return NotificationDigestClaim::unclaimed();
        }

        $sentAt = CarbonImmutable::now()->startOfSecond();

        $claimed = NotificationDigestState::query()
            ->whereKey($state->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('last_digest_sent_at')
                ->orWhere('last_digest_sent_at', '<', $cycleStart->utc()))
            ->toBase()
            ->update(['last_digest_sent_at' => $sentAt]);

        if ($claimed === 0) {
            return NotificationDigestClaim::unclaimed();
        }

        return new NotificationDigestClaim(
            true,
            (string) $state->getKey(),
            $sentAt->toIso8601ZuluString(),
            $state->last_digest_sent_at?->toIso8601ZuluString(),
            $this->collectedSince($state, $sentAt)->toIso8601ZuluString(),
        );
    }

    private function collectedSince(NotificationDigestState $state, CarbonImmutable $sentAt): CarbonInterface
    {
        if ($state->last_digest_sent_at !== null) {
            return $state->last_digest_sent_at;
        }

        $periodStart = $state->frequency === DigestFrequency::Weekly ? $sentAt->subWeek() : $sentAt->subDay();

        return $state->updated_at !== null && $state->updated_at->greaterThan($periodStart) ? $state->updated_at : $periodStart;
    }
}
