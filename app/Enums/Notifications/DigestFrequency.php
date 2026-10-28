<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum DigestFrequency: string
{
    case Daily = 'daily';

    case Weekly = 'weekly';
}
