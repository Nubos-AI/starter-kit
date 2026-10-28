<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

enum WebhookSubscriptionStatus: string
{
    case Pending = 'pending';

    case Active = 'active';

    case Disabled = 'disabled';
}
