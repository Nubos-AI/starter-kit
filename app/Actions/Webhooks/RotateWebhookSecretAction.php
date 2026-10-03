<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Models\WebhookSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RotateWebhookSecretAction
{
    public function execute(WebhookSubscription $subscription): string
    {
        $secret = Str::random(64);

        $subscription->update([
            'secret_previous' => $subscription->secret,
            'secret' => $secret,
            'rotated_at' => Carbon::now(),
        ]);

        return $secret;
    }
}
