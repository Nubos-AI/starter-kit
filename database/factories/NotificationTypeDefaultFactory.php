<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\NotificationChannel;
use App\Models\NotificationTypeDefault;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTypeDefault>
 */
class NotificationTypeDefaultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => 'record.assigned',
            'channel' => NotificationChannel::InApp,
            'enabled' => true,
            'delivery_mode' => DeliveryMode::Immediate,
        ];
    }
}
