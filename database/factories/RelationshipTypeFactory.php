<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RelationshipType>
 */
class RelationshipTypeFactory extends Factory
{
    public function definition(): array
    {
        $token = fake()->unique()->word();
        $suffix = (string) fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'key' => Str::slug($token).'-'.$suffix,
            'inverse_key' => 'inverse-'.Str::slug($token).'-'.$suffix,
            'name' => Str::title($token),
            'inverse_name' => 'inverse-'.Str::title($token),
            'from_object_type_id' => ObjectType::factory(),
            'to_object_type_id' => ObjectType::factory(),
            'cardinality' => RelationCardinality::OneToMany,
            'is_required' => false,
            'is_hierarchy' => false,
            'cascade_behavior' => CascadeBehavior::Nullify,
        ];
    }

    public function oneToMany(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cardinality' => RelationCardinality::OneToMany,
        ]);
    }

    public function manyToMany(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cardinality' => RelationCardinality::ManyToMany,
        ]);
    }

    public function hierarchyFor(ObjectType $objectType): static
    {
        return $this->state(fn (array $attributes): array => [
            'from_object_type_id' => $objectType->getKey(),
            'to_object_type_id' => $objectType->getKey(),
            'is_hierarchy' => true,
        ]);
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_required' => true,
        ]);
    }

    public function cascade(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cascade_behavior' => CascadeBehavior::Cascade,
        ]);
    }

    public function restrict(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cascade_behavior' => CascadeBehavior::Restrict,
        ]);
    }

    public function nullify(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cascade_behavior' => CascadeBehavior::Nullify,
        ]);
    }
}
