<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Goals\GoalDirection;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Models\Goal;
use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'tenant_id' => fn (array $attributes): string => Report::withoutTenantScope()
                ->whereKey($attributes['report_id'])
                ->firstOrFail()
                ->tenant_id,
            'owner_id' => User::factory(),
            'target_user_id' => User::factory(),
            'target_team_id' => null,
            'includes_subteams' => false,
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'scope_type' => GoalScopeType::User,
            'scope_field_key' => 'owner_id',
            'period_type' => GoalPeriodType::Month,
            'direction' => GoalDirection::AtLeast,
            'target_value' => 1000,
        ];
    }

    public function forUser(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => GoalScopeType::User,
            'target_user_id' => User::factory(),
            'target_team_id' => null,
            'scope_field_key' => 'owner_id',
        ]);
    }

    public function forTeam(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => GoalScopeType::Team,
            'target_user_id' => null,
            'target_team_id' => fn (array $attributes): string => (string) Team::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'scope_field_key' => 'team_id',
        ]);
    }

    public function forTenant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => GoalScopeType::Tenant,
            'target_user_id' => null,
            'target_team_id' => null,
            'scope_field_key' => null,
        ]);
    }
}
