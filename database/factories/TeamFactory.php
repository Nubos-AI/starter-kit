<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).' Team';

        return [
            'tenant_id' => Tenant::factory(),
            'parent_team_id' => null,
            'owner_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
        ];
    }

    public function root(): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_team_id' => null,
        ]);
    }

    public function childOf(Team $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => (string) $parent->getAttribute('tenant_id'),
            'parent_team_id' => (string) $parent->getKey(),
        ]);
    }
}
