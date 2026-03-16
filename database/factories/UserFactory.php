<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Salutation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'salutation' => fake()->randomElement(Salutation::cases()),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'email_verified_at' => null,
            ],
        );
    }

    public function withTwoFactor(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'two_factor_secret' => encrypt('secret'),
                'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
                'two_factor_confirmed_at' => now(),
            ],
        );
    }
}
