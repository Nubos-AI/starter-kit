<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FieldDefinitionVersion;
use App\Models\ObjectType;
use App\Models\ObjectTypeDefinitionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FieldDefinitionVersion>
 */
class FieldDefinitionVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'object_type_definition_version_id' => ObjectTypeDefinitionVersion::factory(),
            'object_type_id' => ObjectType::factory(),
            'field_definition_id' => (string) Str::ulid(),
            'field_key' => fake()->unique()->lexify('field_???'),
            'snapshot' => [],
            'version_number' => 1,
            'changed_at' => now(),
        ];
    }
}
