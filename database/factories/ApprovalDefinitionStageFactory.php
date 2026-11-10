<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Approvals\ApprovalQuorumType;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalDefinitionStage>
 */
class ApprovalDefinitionStageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'approval_definition_id' => ApprovalDefinition::factory(),
            'position' => 1,
            'quorum_type' => ApprovalQuorumType::Any,
            'quorum_count' => null,
            'deadline_hours' => null,
            'escalation_type' => null,
            'candidate_sources' => [],
            'escalation_sources' => null,
        ];
    }
}
