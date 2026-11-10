<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalProcess;
use App\Models\CustomRecord;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalProcess>
 */
class ApprovalProcessFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'record_id' => CustomRecord::factory(),
            'approval_definition_id' => ApprovalDefinition::factory(),
            'anchor_type' => CustomRecord::class,
            'anchor_id' => CustomRecord::factory(),
            'triggered_by_id' => null,
            'status' => ApprovalProcessStatus::Pending,
            'attempt' => 1,
            'current_stage_position' => 1,
            'record_version' => 1,
            'cancellation_reason' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    public function forOperation(string $anchorType, string $anchorId): static
    {
        return $this->state(fn (): array => [
            'record_id' => null,
            'anchor_type' => $anchorType,
            'anchor_id' => $anchorId,
            'record_version' => null,
        ]);
    }
}
