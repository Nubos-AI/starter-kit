<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AbsenceDelegation;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbsenceDelegation>
 */
class AbsenceDelegationFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now()->startOfHour();

        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'delegate_id' => User::factory(),
            'created_by_id' => fn (array $attributes): mixed => $attributes['user_id'],
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addWeek(),
        ];
    }
}
