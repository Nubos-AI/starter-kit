<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Webhooks\WebhookEventType;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\ObjectType;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookSubscription>
 */
class WebhookSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'target_url' => 'https://'.fake()->domainName().'/webhooks/'.fake()->uuid(),
            'auth_username' => null,
            'auth_password' => null,
            'secret' => Str::random(64),
            'secret_previous' => null,
            'rotated_at' => null,
            'status' => WebhookSubscriptionStatus::Pending,
            'service_user_id' => null,
            'role_id' => null,
            'event_types' => WebhookEventType::cases(),
            'object_type_id' => null,
            'consecutive_failures' => 0,
            'activated_at' => null,
            'last_error' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => WebhookSubscriptionStatus::Active]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => WebhookSubscriptionStatus::Disabled]);
    }

    public function forObjectType(ObjectType $objectType): static
    {
        return $this->state(fn (): array => ['object_type_id' => $objectType->getKey()]);
    }

    public function rotated(): static
    {
        return $this->state(fn (): array => [
            'secret_previous' => Str::random(64),
            'rotated_at' => now(),
        ]);
    }
}
