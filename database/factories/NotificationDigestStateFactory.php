<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Notifications\DigestFrequency;
use App\Models\NotificationDigestState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDigestState>
 */
class NotificationDigestStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'frequency' => DigestFrequency::Daily,
            'hour' => fake()->numberBetween(0, 23),
            'last_digest_sent_at' => null,
        ];
    }
}
