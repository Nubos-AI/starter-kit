<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldDefinition>
 */
class FieldDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'object_type_id' => ObjectType::factory(),
            'key' => fake()->unique()->lexify('field_???'),
            'field_type' => FieldType::TextShort,
            'is_required' => false,
            'is_unique' => false,
            'is_searchable' => false,
            'is_translatable' => false,
            'is_encrypted' => false,
            'is_sortable' => false,
            'is_filterable' => false,
            'config' => null,
            'validation_rules' => null,
            'default_value' => null,
            'i18n_labels' => null,
        ];
    }

    public function sortable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_sortable' => true,
        ]);
    }

    public function filterable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_filterable' => true,
        ]);
    }

    public function encrypted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_encrypted' => true,
        ]);
    }

    public function ofType(FieldType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'field_type' => $type,
        ]);
    }
}
