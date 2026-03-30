<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'group' => null,
            'scope' => 'organization',
            'is_system' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => 'platform',
        ]);
    }

    public function organization(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => 'organization',
        ]);
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => 'team',
        ]);
    }

    public function inGroup(string $group): static
    {
        return $this->state(fn (array $attributes): array => [
            'group' => $group,
        ]);
    }
}
