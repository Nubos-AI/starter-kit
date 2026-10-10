<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Engine\RelationCardinality;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordLink>
 */
class RecordLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'relationship_type_id' => RelationshipType::factory(),
            'from_record_id' => CustomRecord::factory(),
            'to_record_id' => CustomRecord::factory(),
            'from_record_type' => fn (array $attributes): string => $this->objectTypeOf($attributes['from_record_id']),
            'to_record_type' => fn (array $attributes): string => $this->objectTypeOf($attributes['to_record_id']),
            'cardinality' => RelationCardinality::OneToMany,
            'position' => 0,
        ];
    }

    private function objectTypeOf(mixed $recordId): string
    {
        return (string) CustomRecord::query()
            ->withoutGlobalScopes()
            ->whereKey($recordId)
            ->value('object_type_id');
    }
}
