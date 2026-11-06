<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Engine\RecordBackfillKind;
use App\Enums\Formulas\BackfillStatus;
use App\Models\ObjectType;
use App\Models\RecordBackfillRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordBackfillRun>
 */
class RecordBackfillRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'object_type_id' => ObjectType::factory(),
            'user_id' => User::factory(),
            'kind' => RecordBackfillKind::TimelineProjection,
            'status' => BackfillStatus::Pending,
            'total_count' => 0,
            'processed_count' => 0,
            'error_count' => 0,
            'cursor_id' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BackfillStatus::Running,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BackfillStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    public function stageEnteredAtKind(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => RecordBackfillKind::StageEnteredAt,
        ]);
    }
}
