<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\DigestFrequency;
use App\Enums\Notifications\NotificationChannel;
use App\Mail\NotificationDigestMail;
use App\Models\NotificationDigestState;
use App\Models\NotificationInbox;
use App\Models\NotificationTypeDefault;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DigestRunner
{
    /**
     * @throws Throwable
     */
    public function run(string $tenantId, string $userId, CarbonImmutable $cycleStart): void
    {
        TenantContext::withTenantId($tenantId, function () use ($userId, $cycleStart): void {
            if (TenantContext::current() === null) {
                return;
            }

            $this->deliver($userId, $cycleStart);
        });
    }

    /**
     * @throws Throwable
     */
    private function deliver(string $userId, CarbonImmutable $cycleStart): void
    {
        $state = NotificationDigestState::query()
            ->where('user_id', $userId)
            ->first();

        if ($state === null) {
            return;
        }

        $previousSentAt = $state->last_digest_sent_at;
        $sentAt = CarbonImmutable::now();

        if (!$this->claimCycle($state, $cycleStart, $sentAt)) {
            return;
        }

        try {
            $this->sendDigest($userId, $this->collectDigestEntries($userId, $this->collectedSince($state, $sentAt), $sentAt));
        } catch (Throwable $exception) {
            NotificationDigestState::query()
                ->whereKey($state->getKey())
                ->where('last_digest_sent_at', $sentAt)
                ->toBase()
                ->update(['last_digest_sent_at' => $previousSentAt]);

            throw $exception;
        }
    }

    private function claimCycle(NotificationDigestState $state, CarbonImmutable $cycleStart, CarbonImmutable $sentAt): bool
    {
        $claimed = NotificationDigestState::query()
            ->whereKey($state->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('last_digest_sent_at')
                ->orWhere('last_digest_sent_at', '<', $cycleStart->utc()))
            ->toBase()
            ->update(['last_digest_sent_at' => $sentAt]);

        return $claimed > 0;
    }

    private function collectedSince(NotificationDigestState $state, CarbonImmutable $sentAt): CarbonInterface
    {
        if ($state->last_digest_sent_at !== null) {
            return $state->last_digest_sent_at;
        }

        $periodStart = $state->frequency === DigestFrequency::Weekly ? $sentAt->subWeek() : $sentAt->subDay();

        return $state->updated_at !== null && $state->updated_at->greaterThan($periodStart) ? $state->updated_at : $periodStart;
    }

    /**
     * @param  Collection<int, NotificationInbox>  $entries
     */
    private function sendDigest(string $userId, Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $user = User::query()
            ->active()
            ->whereKey($userId)
            ->first();

        if ($user !== null) {
            Mail::to($user)->send(new NotificationDigestMail($entries));
        }
    }

    /**
     * @return Collection<int, NotificationInbox>
     */
    private function collectDigestEntries(string $userId, CarbonInterface $collectedSince, CarbonImmutable $sentAt): Collection
    {
        $inbox = NotificationInbox::query()
            ->where('user_id', $userId)
            ->where('created_at', '>', $collectedSince)
            ->where('created_at', '<=', $sentAt)
            ->orderBy('created_at')
            ->get();

        if ($inbox->isEmpty()) {
            return $inbox;
        }

        $digestTypes = $this->digestModeTypes($userId, $inbox->pluck('type')->unique()->all());

        return $inbox
            ->filter(fn (NotificationInbox $entry): bool => in_array($entry->type, $digestTypes, true))
            ->values();
    }

    /**
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    private function digestModeTypes(string $userId, array $types): array
    {
        $isDigested = [];

        $defaults = NotificationTypeDefault::query()
            ->where('channel', NotificationChannel::Email)
            ->whereIn('type', $types)
            ->get();

        foreach ($defaults as $default) {
            $isDigested[$default->type] = $default->enabled && $default->delivery_mode === DeliveryMode::Digest;
        }

        $userPreferences = UserNotificationPreference::query()
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::Email)
            ->whereIn('type', $types)
            ->get();

        foreach ($userPreferences as $preference) {
            $isDigested[$preference->type] = $preference->enabled && $preference->delivery_mode === DeliveryMode::Digest;
        }

        $digestTypes = [];

        foreach ($types as $type) {
            if ($isDigested[$type] ?? false) {
                $digestTypes[] = $type;
            }
        }

        return $digestTypes;
    }
}
