<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Engine\StorageStrategy;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\RecordNumberFormatter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ObjectType>
 */
class ObjectTypeFactory extends Factory
{
    public function definition(): array
    {
        $token = fake()->unique()->word();
        $suffix = (string) fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'key' => $token.'_'.$suffix,
            'slug' => Str::slug($token).'-'.$suffix,
            'business_key_prefix' => null,
            'is_system' => false,
            'storage_strategy' => StorageStrategy::Generic,
            'name' => Str::title($token).' '.$suffix,
            'record_number_format' => null,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
            'storage_strategy' => StorageStrategy::Native,
        ]);
    }

    public function native(): static
    {
        return $this->state(fn (array $attributes): array => [
            'storage_strategy' => StorageStrategy::Native,
        ]);
    }

    public function withHierarchy(): static
    {
        return $this->afterCreating(function (ObjectType $objectType): void {
            $carrier = RelationshipType::factory()->hierarchyFor($objectType)->create();

            $objectType->update(['hierarchy_relationship_type_id' => $carrier->getKey()]);
        });
    }

    public function withBusinessKey(string $prefix, ?string $format = null): static
    {
        $format ??= RecordNumberFormatter::$defaultFormat;

        return $this->state(fn (array $attributes): array => [
            'business_key_prefix' => $prefix,
            'record_number_format' => $format,
        ]);
    }
}
