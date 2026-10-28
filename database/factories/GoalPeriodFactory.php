<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Goal;
use App\Models\GoalPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalPeriod>
 */
class GoalPeriodFactory extends Factory
{
    public function definition(): array
    {
        $periodStart = CarbonImmutable::now()->startOfMonth();

        return [
            'goal_id' => Goal::factory(),
            'tenant_id' => fn (array $attributes): string => Goal::withoutTenantScope()
                ->whereKey($attributes['goal_id'])
                ->firstOrFail()
                ->tenant_id,
            'period_start' => $periodStart,
            'period_end' => $periodStart->addMonth(),
            'current_value' => null,
            'calculated_at' => null,
            'triggered_thresholds' => [],
        ];
    }
}
