<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\DigestFrequency;
use App\Enums\Notifications\NotificationChannel;
use App\Models\NotificationDigestState;
use App\Models\NotificationTypeDefault;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class UpdateNotificationPreferencesAction
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(User $user, array $data): NotificationDigestState
    {
        $validated = Validator::make($data, [
            'frequency' => ['sometimes', 'nullable', Rule::enum(DigestFrequency::class)],
            'hour' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_end'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_start'],
            'preferences' => ['sometimes', 'array'],
            'preferences.*.type' => ['required_with:preferences', 'string'],
            'preferences.*.channel' => ['required_with:preferences', Rule::enum(NotificationChannel::class)],
            'preferences.*.enabled' => ['required_with:preferences', 'boolean'],
            'preferences.*.delivery_mode' => ['required_with:preferences', Rule::enum(DeliveryMode::class)],
        ])->validate();

        return DB::transaction(function () use ($user, $validated): NotificationDigestState {
            $this->writeUserOverrides($user, $validated);
            $this->writeChannelPreferences($user, $validated);

            $state = NotificationDigestState::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->getKey())
                ->first();

            $frequency = array_key_exists('frequency', $validated)
                ? $validated['frequency']
                : $state?->frequency;

            $hour = isset($validated['hour']) ? $validated['hour'] : ($state->hour ?? 9);

            return NotificationDigestState::query()->updateOrCreate(
                ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()],
                ['frequency' => $frequency, 'hour' => $hour],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, NotificationTypeDefault>
     *
     * @throws Throwable
     */
    public function writeTenantDefaults(User $user, array $data): array
    {
        $validated = Validator::make($data, [
            'defaults' => ['required', 'array'],
            'defaults.*.type' => ['required', 'string'],
            'defaults.*.channel' => ['required', Rule::enum(NotificationChannel::class)],
            'defaults.*.enabled' => ['required', 'boolean'],
            'defaults.*.delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
        ])->validate();

        /** @var array<int, array<string, mixed>> $defaults */
        $defaults = $validated['defaults'];
        $tenantId = TenantContext::currentId((string) $user->tenant_id);

        return DB::transaction(fn (): array => array_map(
            fn (array $default): NotificationTypeDefault => NotificationTypeDefault::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'type' => $default['type'],
                    'channel' => $default['channel'],
                ],
                [
                    'enabled' => $default['enabled'],
                    'delivery_mode' => $default['delivery_mode'],
                ],
            ),
            $defaults,
        ));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function writeUserOverrides(User $user, array $validated): void
    {
        $attributes = array_intersect_key($validated, array_flip(['timezone', 'quiet_hours_start', 'quiet_hours_end']));

        if ($attributes !== []) {
            $user->fill($attributes)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function writeChannelPreferences(User $user, array $validated): void
    {
        /** @var array<int, array<string, mixed>> $preferences */
        $preferences = $validated['preferences'] ?? [];

        foreach ($preferences as $preference) {
            UserNotificationPreference::query()->updateOrCreate(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->getKey(),
                    'type' => $preference['type'],
                    'channel' => $preference['channel'],
                ],
                [
                    'enabled' => $preference['enabled'],
                    'delivery_mode' => $preference['delivery_mode'],
                ],
            );
        }
    }
}
