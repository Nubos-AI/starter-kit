<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum DeliveryMode: string
{
    case Immediate = 'immediate';

    case Digest = 'digest';
}
