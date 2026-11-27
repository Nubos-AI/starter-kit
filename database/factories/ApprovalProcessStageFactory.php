<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Approvals\ApprovalStageStatus;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalProcessStage>
 */
class ApprovalProcessStageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'approval_process_id' => ApprovalProcess::factory(),
            'approval_definition_stage_id' => ApprovalDefinitionStage::factory(),
            'assigned_user_id' => null,
            'escalation_applied' => false,
            'position' => 1,
            'attempt' => 1,
            'status' => ApprovalStageStatus::Pending,
            'deadline_at' => null,
            'escalation_added_delegations' => null,
            'started_at' => now(),
            'decided_at' => null,
        ];
    }
}
