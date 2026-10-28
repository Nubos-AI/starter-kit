<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSetting>
 */
class TenantSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'import_max_file_bytes' => fake()->numberBetween(1_000_000, 10_485_760),
            'import_max_rows' => fake()->numberBetween(1_000, 50_000),
            'bulk_grouping_threshold' => 10,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'preference_policy' => null,
        ];
    }
}
