<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Engine\MergeRuleMode;
use App\Models\MergeRule;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MergeRule>
 */
class MergeRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'object_type_id' => ObjectType::factory(),
            'name' => fake()->unique()->words(2, true),
            'mode' => MergeRuleMode::Allow,
            'position' => 0,
            'is_active' => true,
            'deny_reason' => null,
            'condition' => null,
            'field_strategies' => [],
            'transfer_policy' => [],
            'options' => [],
        ];
    }

    public function deny(string $reason): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => MergeRuleMode::Deny,
            'deny_reason' => $reason,
            'field_strategies' => [],
            'transfer_policy' => [],
        ]);
    }
}
