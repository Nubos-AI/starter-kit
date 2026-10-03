<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RoutingCursor;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RoutingCursor>
 */
class RoutingCursorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'automation_id' => (string) Str::ulid(),
            'last_user_id' => null,
            'node_id' => fake()->unique()->slug(2),
        ];
    }
}
