<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Authorization\RoleScope;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    public function definition(): array
    {
        $group = fake()->unique()->word();
        $action = fake()->randomElement(['view', 'create', 'update', 'delete']);

        return [
            'name' => "{$group}.{$action}",
            'group' => $group,
            'scope' => RoleScope::Tenant,
            'is_system' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }
}
