<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Sso\IdentityProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserIdentity>
 */
class UserIdentityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(IdentityProvider::cases()),
            'issuer' => 'https://'.fake()->domainName(),
            'subject' => fake()->unique()->uuid(),
        ];
    }
}
