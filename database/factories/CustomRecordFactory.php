<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomRecord>
 */
class CustomRecordFactory extends Factory
{
    /**
     * @param  class-string<CustomRecord>  $model
     */
    public function forModel(string $model): static
    {
        $instance = $this->newInstance();
        $instance->model = $model;

        return $instance;
    }

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'team_id' => null,
            'owner_id' => null,
            'object_type_id' => ObjectType::factory(),
            'record_number' => null,
            'external_reference_id' => null,
            'version' => 1,
            'data' => [
                'titel' => fake()->words(3, true),
                'status' => fake()->randomElement(['offen', 'aktiv', 'geschlossen']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->model = $this->model;

        return $instance;
    }
}
