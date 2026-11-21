<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditEntry;
use App\Models\CustomRecord;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'auditable_type' => (new CustomRecord)->getMorphClass(),
            'auditable_id' => (string) Str::ulid(),
            'field_key' => fake()->lexify('field_???'),
            'old_value' => null,
            'new_value' => fake()->word(),
            'actor_id' => null,
            'version' => 1,
            'changed_at' => now(),
        ];
    }
}
