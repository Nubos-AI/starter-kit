<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\FieldDependency;
use App\Models\RelationshipType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldDependency>
 */
class FieldDependencyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rollup_field_id' => FieldDefinition::factory()->ofType(FieldType::Rollup),
            'depends_on_field_id' => FieldDefinition::factory(),
            'relationship_type_id' => RelationshipType::factory(),
        ];
    }

    public function withoutRelationship(): static
    {
        return $this->state(fn (array $attributes): array => [
            'relationship_type_id' => null,
        ]);
    }
}
