<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoVerifiedDomain>
 */
class SsoVerifiedDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sso_connection_id' => SsoConnection::factory(),
            'domain' => fake()->unique()->domainName(),
        ];
    }
}
