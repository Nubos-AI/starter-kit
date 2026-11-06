<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomRecord;
use App\Models\Tenant;
use App\Models\TimelineEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimelineEntry>
 */
class TimelineEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'record_id' => CustomRecord::factory(),
            'source_key' => 'note',
            'source_id' => null,
            'actor_id' => null,
            'actor_type' => null,
            'occurred_at' => now(),
            'payload' => ['summary' => fake()->sentence()],
        ];
    }

    public function forSource(string $sourceKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_key' => $sourceKey,
        ]);
    }

    public function occurredAt(CarbonInterface $moment): static
    {
        return $this->state(fn (array $attributes): array => [
            'occurred_at' => $moment,
        ]);
    }

    public function byActor(User $actor): static
    {
        return $this->state(fn (array $attributes): array => [
            'actor_id' => $actor->getKey(),
            'actor_type' => $actor->getMorphClass(),
        ]);
    }
}
