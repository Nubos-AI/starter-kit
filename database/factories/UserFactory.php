<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'status' => UserStatus::Accepted,
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
            ]
        );
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Invited,
            'salutation' => Salutation::Unknown,
            'first_name' => null,
            'last_name' => null,
            'password' => null,
            'email_verified_at' => null,
            'remember_token' => null,
            'invitation_token_hash' => hash('sha256', Str::random(64)),
            'invitation_sent_at' => now(),
            'invitation_expires_at' => now()->addDays(7),
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Blocked,
        ]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
