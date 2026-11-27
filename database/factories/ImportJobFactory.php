<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Import\ImportJobStatus;
use App\Models\ImportJob;
use App\Models\ObjectType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'object_type_id' => ObjectType::factory(),
            'user_id' => User::factory(),
            'status' => ImportJobStatus::Pending,
            'original_filename' => fake()->word().'.csv',
            'source_path' => 'imports/'.Str::ulid().'.csv',
            'source_disk' => 'local',
            'format' => 'csv',
            'mapping' => ['columns' => ['Name' => 'name']],
            'duplicate_mode' => 'insert',
            'total_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'error_count' => 0,
            'error_report_path' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportJobStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportJobStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportJobStatus::Failed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function withErrorReport(): static
    {
        return $this->state(fn (array $attributes): array => [
            'error_count' => 1,
            'error_report_path' => 'imports/errors/'.Str::ulid().'.csv',
        ]);
    }
}
