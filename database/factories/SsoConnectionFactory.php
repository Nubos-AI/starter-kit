<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SsoConnection;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SsoConnection>
 */
class SsoConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'default_role_id' => null,
            'configured_by_id' => null,
            'is_enabled' => true,
            'is_enforced' => false,
            'is_jit_enabled' => false,
            'issuer_url' => 'https://'.fake()->unique()->domainName(),
            'client_id' => fake()->uuid(),
            'client_secret' => fake()->sha256(),
            'allowed_domains' => [fake()->unique()->domainName()],
            'domain_proof_nonce' => Str::random(40),
        ];
    }

    public function enforced(): static
    {
        return $this->state(fn (): array => ['is_enforced' => true]);
    }

    public function withJustInTimeProvisioning(): static
    {
        return $this->state(fn (): array => ['is_jit_enabled' => true]);
    }
}
