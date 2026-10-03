<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Approvals\ApprovalEventType;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalEvent>
 */
class ApprovalEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'approval_process_id' => ApprovalProcess::factory(),
            'approval_process_stage_id' => null,
            'actor_id' => null,
            'on_behalf_of_id' => null,
            'type' => ApprovalEventType::Started,
            'reason' => null,
            'payload' => null,
            'occurred_at' => now(),
        ];
    }
}
