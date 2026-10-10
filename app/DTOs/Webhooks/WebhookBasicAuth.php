<?php

declare(strict_types=1);

namespace App\DTOs\Webhooks;

use App\Models\WebhookSubscription;

class WebhookBasicAuth
{
    public function __construct(
        public string $username,
        public string $password,
    ) {}

    public static function forSubscription(WebhookSubscription $subscription): ?self
    {
        $username = $subscription->auth_username;

        if ($username === null || $username === '') {
            return null;
        }

        return new self($username, $subscription->auth_password ?? '');
    }
}
