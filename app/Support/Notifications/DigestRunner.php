<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\NotificationChannel;
use App\Mail\NotificationDigestMail;
use App\Models\NotificationDigestState;
use App\Models\NotificationInbox;
use App\Models\NotificationTypeDefault;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

class DigestRunner
{
    public function run(string $tenantId, string $userId): void
    {
        TenantContext::withTenantId($tenantId, function () use ($userId): void {
            if (TenantContext::current() === null) {
                return;
            }

            $this->deliver($userId);
        });
    }

    private function deliver(string $userId): void
    {
        $state = NotificationDigestState::query()
            ->where('user_id', $userId)
            ->first();

        if ($state === null) {
            return;
        }

        $entries = $this->collectDigestEntries($state, $userId);

        if ($entries->isNotEmpty()) {
            $user = User::query()->whereKey($userId)->first();

            if ($user !== null) {
                Mail::to($user)->send(new NotificationDigestMail($entries));
            }
        }

        $state->update(['last_digest_sent_at' => now()]);
    }

    /**
     * @return Collection<int, NotificationInbox>
     */
    private function collectDigestEntries(NotificationDigestState $state, string $userId): Collection
    {
        $inbox = NotificationInbox::query()
            ->where('user_id', $userId)
            ->when(
                $state->last_digest_sent_at !== null,
                fn ($query) => $query->where('created_at', '>', $state->last_digest_sent_at),
            )
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
        $modes = [];

        $defaults = NotificationTypeDefault::query()
            ->where('channel', NotificationChannel::Email)
            ->whereIn('type', $types)
            ->get();

        foreach ($defaults as $default) {
            $modes[$default->type] = $default->delivery_mode;
        }

        $userPreferences = UserNotificationPreference::query()
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::Email)
            ->whereIn('type', $types)
            ->get();

        foreach ($userPreferences as $preference) {
            $modes[$preference->type] = $preference->delivery_mode;
        }

        $digestTypes = [];

        foreach ($types as $type) {
            if (($modes[$type] ?? null) === DeliveryMode::Digest) {
                $digestTypes[] = $type;
            }
        }

        return $digestTypes;
    }
}
