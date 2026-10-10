<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Models\ObjectType;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamRecordAccessRule>
 */
class TeamRecordAccessRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'team_id' => Team::factory(),
            'object_type_id' => ObjectType::factory(),
            'created_by_id' => null,
            'is_active' => true,
            'inheritance' => TeamAccessRuleInheritance::Intersect,
            'filter_definition' => [
                'combinator' => 'and',
                'conditions' => [],
            ],
        ];
    }

    public function overriding(): static
    {
        return $this->state(fn (array $attributes): array => [
            'inheritance' => TeamAccessRuleInheritance::Override,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
