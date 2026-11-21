<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'nubos/'.fake()->unique()->slug(2),
            'disabled' => false,
            'version' => '1.0.0',
            'installed_at' => now(),
        ];
    }

    public function unregistered(): static
    {
        return $this->state(fn (): array => ['version' => null, 'installed_at' => null]);
    }
}
