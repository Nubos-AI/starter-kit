<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PromotionBaseline;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromotionBaseline>
 */
class PromotionBaselineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'counterpart_key' => 'sandbox:'.Str::ulid()->toBase32(),
            'artifact_key' => 'object_type:'.fake()->word(),
            'hash' => hash('sha256', Str::ulid()->toBase32()),
            'synced_at' => now(),
        ];
    }
}
