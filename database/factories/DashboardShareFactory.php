<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardShare>
 */
class DashboardShareFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'tenant_id' => fn (array $attributes): string => Dashboard::withoutTenantScope()
                ->whereKey($attributes['dashboard_id'])
                ->firstOrFail()
                ->tenant_id,
            'grantee_type' => (new User)->getMorphClass(),
            'grantee_id' => User::factory(),
            'can_edit' => false,
            'granted_by' => User::factory(),
        ];
    }

    public function canEdit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'can_edit' => true,
        ]);
    }
}
