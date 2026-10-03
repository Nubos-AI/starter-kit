<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ObjectType;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'object_type_id' => ObjectType::factory(),
            'record_id' => (string) Str::ulid(),
            'version' => 1,
            'changed_field_keys' => ['titel'],
            'created_at' => now(),
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now(),
        ]);
    }
}
