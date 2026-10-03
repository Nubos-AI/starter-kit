<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomRecord;
use App\Models\RecordActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecordActivity> */
class RecordActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'record_id' => CustomRecord::factory(),
            'tenant_id' => fn (array $attributes): string => CustomRecord::withoutTenantScope()->whereKey($attributes['record_id'])->firstOrFail()->tenant_id,
            'subject' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
