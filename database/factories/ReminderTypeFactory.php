<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReminderType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderType>
 */
class ReminderTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
        ];
    }
}
