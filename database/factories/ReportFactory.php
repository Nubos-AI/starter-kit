<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Enums\Reports\ReportExecutionMode;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'owner_id' => User::factory(),
            'object_type_id' => ObjectType::factory(),
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'description' => null,
            'filter_definition' => [],
            'aggregation_type' => AggregationType::Count,
            'aggregation_field_key' => null,
            'group_by_field_key' => null,
            'group_by_bucket' => null,
            'series_field_key' => null,
            'chart_type' => ChartType::Metric,
            'execution_mode' => ReportExecutionMode::Viewer,
        ];
    }

    public function definerBound(): static
    {
        return $this->state(fn (array $attributes): array => [
            'execution_mode' => ReportExecutionMode::Definer,
        ]);
    }
}
