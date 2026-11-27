<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

enum DeliveryOutcome: string
{
    case Delivered = 'delivered';

    case Parked = 'parked';

    case Gone = 'gone';

    case Skipped = 'skipped';

    case Suppressed = 'suppressed';
}
