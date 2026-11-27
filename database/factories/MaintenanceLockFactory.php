<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Maintenance\MaintenanceLockReason;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Models\MaintenanceLock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceLock>
 */
class MaintenanceLockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'acquired_by_id' => User::factory(),
            'released_by_id' => null,
            'reason' => MaintenanceLockReason::Manual,
            'status' => MaintenanceLockStatus::Active,
            'release_mode' => null,
            'suspended_schedule_ids' => null,
            'note' => null,
            'release_note' => null,
            'acquired_at' => now(),
            'released_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MaintenanceLockStatus::Released,
            'release_mode' => MaintenanceLockRelease::Manual,
            'released_at' => now(),
        ]);
    }
}
