<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Contracts\Mail\SystemMailDispatcherInterface;
use App\DTOs\Notifications\NotificationDigestClaim;
use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\NotificationChannel;
use App\Mail\NotificationDigestMail;
use App\Models\NotificationInbox;
use App\Models\NotificationTypeDefault;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class SendNotificationDigestAction
{
    public function __construct(private readonly SystemMailDispatcherInterface $systemMail) {}

    public function execute(string $userId, NotificationDigestClaim $claim): void
    {
        if (!$claim->isClaimed || $claim->sentAt === null || $claim->collectedSince === null) {
            return;
        }

        $sentAt = CarbonImmutable::parse($claim->sentAt);
        $entries = $this->digestEntries($userId, CarbonImmutable::parse($claim->collectedSince), $sentAt);

        if ($entries->isEmpty()) {
            return;
        }

        $user = User::query()->active()->whereKey($userId)->first();

        if (!$user instanceof User) {
            return;
        }

        $this->systemMail->deliverMailable(
            (new NotificationDigestMail($entries))->to($user),
            "digest-{$userId}-{$sentAt->getTimestamp()}",
            $user->tenant_id,
        );
    }

    /**
     * @return Collection<int, NotificationInbox>
     */
    private function digestEntries(string $userId, CarbonImmutable $collectedSince, CarbonImmutable $sentAt): Collection
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

        return array_values(array_filter($types, static fn (string $type): bool => $isDigested[$type] ?? false));
    }
}
