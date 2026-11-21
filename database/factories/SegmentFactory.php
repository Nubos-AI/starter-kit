<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'owner_id' => User::factory(),
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'object_type_id' => ObjectType::factory(),
            'filter_definition' => [],
            'is_system' => false,
            'is_default' => false,
            'i18n_labels' => null,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }

    public function crossObject(): static
    {
        return $this->state(fn (array $attributes): array => [
            'object_type_id' => null,
        ]);
    }
}
