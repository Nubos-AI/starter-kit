<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Export\ExportFormat;
use App\Enums\Export\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\ObjectType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportJob>
 */
class ExportJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'object_type_id' => ObjectType::factory(),
            'user_id' => User::factory(),
            'status' => ExportJobStatus::Pending,
            'format' => ExportFormat::Csv,
            'scope' => ['mode' => 'whole-type'],
            'fields' => [],
            'result_path' => null,
            'row_count' => 0,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ExportJobStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ExportJobStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
            'result_path' => 'exports/'.fake()->uuid().'.csv',
            'row_count' => fake()->numberBetween(1, 100),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ExportJobStatus::Failed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function format(ExportFormat $format): static
    {
        return $this->state(fn (array $attributes): array => [
            'format' => $format,
        ]);
    }
}
