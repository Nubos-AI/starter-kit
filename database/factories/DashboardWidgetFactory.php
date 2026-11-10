<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Goal;
use App\Models\ObjectType;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'tenant_id' => fn (array $attributes): string => Dashboard::withoutTenantScope()
                ->whereKey($attributes['dashboard_id'])
                ->firstOrFail()
                ->tenant_id,
            'report_id' => fn (array $attributes): string => (string) Report::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'goal_id' => null,
            'title' => null,
            'chart_type' => ChartType::Bar,
            'definition' => null,
            'position' => 0,
            'column_span' => 1,
        ];
    }

    public function referencingReport(): static
    {
        return $this->state(fn (array $attributes): array => [
            'report_id' => fn (array $attributes): string => (string) Report::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'definition' => null,
        ]);
    }

    public function referencingGoal(Goal $goal): static
    {
        return $this->state(fn (array $attributes): array => [
            'report_id' => null,
            'goal_id' => (string) $goal->getKey(),
            'chart_type' => null,
            'definition' => null,
        ]);
    }

    public function embedded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'report_id' => null,
            'definition' => $this->embeddedDefinition(
                (string) ObjectType::factory()->create()->getKey(),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function embeddedFor(ObjectType $objectType, array $definition = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'report_id' => null,
            'definition' => array_merge(
                $this->embeddedDefinition((string) $objectType->getKey()),
                $definition,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddedDefinition(string $objectTypeId): array
    {
        return [
            'object_type_id' => $objectTypeId,
            'filter_definition' => [],
            'aggregation_type' => AggregationType::Count->value,
            'aggregation_field_key' => null,
            'group_by_field_key' => null,
            'group_by_bucket' => null,
            'series_field_key' => null,
        ];
    }
}
