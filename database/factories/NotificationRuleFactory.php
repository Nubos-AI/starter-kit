<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Notifications\RuleActionType;
use App\Enums\Notifications\RuleTriggerType;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRule>
 */
class NotificationRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'object_type_id' => ObjectType::factory(),
            'name' => fake()->unique()->words(3, true),
            'trigger_type' => RuleTriggerType::DateBased,
            'config' => ['date_field_key' => 'faellig', 'lead_stages' => [['value' => 3, 'unit' => 'day']]],
            'segment_id' => null,
            'filter_definition' => null,
            'action' => [RuleActionType::Notify->value => true, RuleActionType::CreateReminder->value => null],
            'is_active' => true,
            'created_by_id' => User::factory(),
        ];
    }

    public function dateBased(): static
    {
        return $this->state(fn (): array => [
            'trigger_type' => RuleTriggerType::DateBased,
            'config' => ['date_field_key' => 'faellig', 'lead_stages' => [['value' => 7, 'unit' => 'day'], ['value' => 3, 'unit' => 'day']]],
        ]);
    }

    public function fieldChange(): static
    {
        return $this->state(fn (): array => [
            'trigger_type' => RuleTriggerType::FieldChange,
            'config' => ['watched_field_keys' => ['status']],
        ]);
    }

    public function withReminder(): static
    {
        return $this->state(fn (): array => [
            'action' => [RuleActionType::Notify->value => true, RuleActionType::CreateReminder->value => ['subject' => fake()->sentence(3), 'due_offset' => 3]],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
