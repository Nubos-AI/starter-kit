<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Authorization\RoleScope;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'scope' => RoleScope::Tenant,
            'authority' => null,
            'is_system' => false,
            'grants_subteam_visibility' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }

    public function grantingSubteamVisibility(): static
    {
        return $this->state(fn (array $attributes): array => [
            'grants_subteam_visibility' => true,
        ]);
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => RoleScope::Platform,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => RoleScope::Platform,
            'authority' => RoleAuthority::SuperAdmin,
        ]);
    }

    public function scopeAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'authority' => RoleAuthority::ScopeAdmin,
        ]);
    }
}
