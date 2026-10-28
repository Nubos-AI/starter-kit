<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Models\PromotionRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromotionRun>
 */
class PromotionRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'source_tenant_id' => null,
            'triggered_by_id' => User::factory(),
            'snapshot_group_id' => null,
            'counterpart_key' => 'sandbox:'.Str::ulid()->toBase32(),
            'direction' => PromotionDirection::TenantToProduction,
            'status' => PromotionRunStatus::Draft,
            'selection' => [],
            'conflict_decisions' => [],
            'report' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
