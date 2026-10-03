<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'owner_id' => User::factory(),
            'is_tenant_wide' => false,
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'description' => null,
        ];
    }

    public function tenantWide(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_tenant_wide' => true,
        ]);
    }
}
