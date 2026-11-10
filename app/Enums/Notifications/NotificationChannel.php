<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum NotificationChannel: string
{
    case InApp = 'in-app';

    case Email = 'email';

    case WebPush = 'web-push';
}
