<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationInbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationInbox>
 */
class NotificationInboxFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'record.assigned',
            'data' => [
                'title' => fake()->sentence(),
                'body' => fake()->sentence(),
            ],
            'priority' => 0,
            'read_at' => null,
            'snoozed_until' => null,
            'archived_at' => null,
        ];
    }
}
