<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Engine\AgingClock;
use App\Enums\Engine\AgingThresholdColor;
use App\Models\AgingRule;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgingRule>
 */
class AgingRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'object_type_id' => ObjectType::factory(),
            'name' => fake()->unique()->words(2, true),
            'clock' => AgingClock::UpdatedAt->value,
            'clock_field_key' => null,
            'condition' => null,
            'thresholds' => [
                ['after_days' => 7, 'color' => AgingThresholdColor::Amber->value],
                ['after_days' => 30, 'color' => AgingThresholdColor::Red->value],
            ],
            'is_active' => true,
        ];
    }

    public function updatedAtClock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'clock' => AgingClock::UpdatedAt->value,
            'clock_field_key' => null,
        ]);
    }

    public function fieldClock(string $fieldKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'clock' => AgingClock::Field->value,
            'clock_field_key' => $fieldKey,
        ]);
    }
}
