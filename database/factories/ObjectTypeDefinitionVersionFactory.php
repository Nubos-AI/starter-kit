<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ObjectType;
use App\Models\ObjectTypeDefinitionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObjectTypeDefinitionVersion>
 */
class ObjectTypeDefinitionVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'object_type_id' => ObjectType::factory(),
            'version_number' => 1,
            'snapshot' => [
                'object_type' => [],
                'field_definitions' => [],
            ],
            'actor_id' => null,
            'change_summary' => fake()->sentence(),
            'changed_at' => now(),
        ];
    }
}
