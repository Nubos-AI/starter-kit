<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScimToken;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScimToken>
 */
class ScimTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'token_hash' => fake()->unique()->sha256(),
            'last_used_at' => null,
        ];
    }
}
