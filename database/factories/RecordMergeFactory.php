<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordMerge;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordMerge>
 */
class RecordMergeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'object_type_id' => ObjectType::factory(),
            'target_record_id' => CustomRecord::factory(),
            'source_record_id' => CustomRecord::factory(),
            'merge_rule_id' => null,
            'actor_id' => null,
            'reason' => null,
            'resolution' => [],
            'transfers' => [],
            'undone_at' => null,
        ];
    }
}
