<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\Models\ApprovalDefinition;
use App\Models\CustomRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalDefinition>
 */
class ApprovalDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'anchor_type' => CustomRecord::class,
            'anchor_id' => CustomRecord::factory(),
            'is_active' => true,
            'exclusions' => ApprovalExclusionSet::strict()->toArray(),
        ];
    }
}
