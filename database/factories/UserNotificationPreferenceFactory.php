<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\NotificationChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserNotificationPreference>
 */
class UserNotificationPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'type' => 'record.assigned',
            'channel' => NotificationChannel::InApp,
            'enabled' => true,
            'delivery_mode' => DeliveryMode::Immediate,
        ];
    }
}
