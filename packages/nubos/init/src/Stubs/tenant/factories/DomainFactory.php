<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Domain> */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => fake()->unique()->domainWord(),
            'is_primary' => true,
        ];
    }
}
