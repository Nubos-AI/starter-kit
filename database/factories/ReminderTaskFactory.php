<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReminderTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderTask>
 */
class ReminderTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'creator_id' => User::factory(),
            'assignee_id' => User::factory(),
            'record_id' => null,
            'reminder_type_id' => null,
            'due_at' => fake()->dateTimeBetween('now', '+2 weeks'),
            'subject' => fake()->sentence(4),
            'note' => fake()->optional()->paragraph(),
            'done_at' => null,
            'notified_at' => null,
        ];
    }
}
