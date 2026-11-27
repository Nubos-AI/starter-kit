<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldPermission>
 */
class FieldPermissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'field_definition_id' => FieldDefinition::factory(),
            'can_read' => false,
            'can_write' => false,
        ];
    }

    public function canRead(): static
    {
        return $this->state(fn (array $attributes): array => [
            'can_read' => true,
        ]);
    }

    public function canWrite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'can_write' => true,
        ]);
    }

    public function readWrite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'can_read' => true,
            'can_write' => true,
        ]);
    }
}
