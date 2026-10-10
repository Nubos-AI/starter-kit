<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\NotificationChannel;
use App\Models\NotificationTypeDefault;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Channels\InboxChannel;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushChannel;

class ChannelPreferenceResolver
{
    /**
     * @return array{enabled: bool, delivery_mode: DeliveryMode}
     */
    public function resolve(User $user, string $type, NotificationChannel $channel): array
    {
        $preference = UserNotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->where('channel', $channel)
            ->first();

        if ($preference !== null) {
            return ['enabled' => $preference->enabled, 'delivery_mode' => $preference->delivery_mode];
        }

        $default = NotificationTypeDefault::query()
            ->where('type', $type)
            ->where('channel', $channel)
            ->first();

        if ($default !== null) {
            return ['enabled' => $default->enabled, 'delivery_mode' => $default->delivery_mode];
        }

        return ['enabled' => true, 'delivery_mode' => DeliveryMode::Immediate];
    }

    /**
     * @return array<int, string>
     */
    public function channelsFor(User $user, string $type): array
    {
        $channels = [];

        if ($this->resolve($user, $type, NotificationChannel::InApp)['enabled']) {
            $channels[] = InboxChannel::class;
        }

        $quiet = $this->inQuietHours($user);

        $email = $this->resolve($user, $type, NotificationChannel::Email);

        if ($email['enabled'] && $email['delivery_mode'] === DeliveryMode::Immediate && !$quiet) {
            $channels[] = 'mail';
        }

        $push = $this->resolve($user, $type, NotificationChannel::WebPush);

        if ($push['enabled'] && $push['delivery_mode'] === DeliveryMode::Immediate && !$quiet && $user->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function inQuietHours(User $user): bool
    {
        $start = $user->quiet_hours_start;
        $end = $user->quiet_hours_end;

        if ($start === null || $end === null) {
            $settings = TenantSetting::forTenant((string) $user->tenant_id);
            $start = $settings->quiet_hours_start;
            $end = $settings->quiet_hours_end;
        }

        if ($start === null || $end === null) {
            return false;
        }

        $timezone = $user->timezone ?? (string) config('app.timezone');
        $now = Carbon::now($timezone);

        $current = $now->hour * 60 + $now->minute;
        $startMinutes = $this->toMinutes($start);
        $endMinutes = $this->toMinutes($end);

        if ($startMinutes <= $endMinutes) {
            return $current >= $startMinutes && $current < $endMinutes;
        }

        return $current >= $startMinutes || $current < $endMinutes;
    }

    private function toMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
    }
}
