<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomRecord;
use App\Models\RecordActivity;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecordActivity> */
class RecordActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'record_id' => CustomRecord::factory(),
            'tenant_id' => fn (array $attributes): string => CustomRecord::withoutTenantScope()->whereKey($attributes['record_id'])->firstOrFail()->tenant_id,
            'source_type' => null,
            'source_id' => null,
            'subject' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }

    public function fromSource(string $sourceType, string $sourceId): static
    {
        return $this->state(fn (): array => [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    public function withoutRecord(): static
    {
        return $this->state(fn (): array => [
            'record_id' => null,
            'tenant_id' => Tenant::factory(),
        ]);
    }
}
