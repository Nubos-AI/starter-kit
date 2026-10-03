<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum SuppressedChannel: string
{
    case Mail = 'mail';

    case WebPush = 'web-push';

    case Webhook = 'webhook';

    case Schedule = 'schedule';

    case HttpRequest = 'http-request';

    public function label(): string
    {
        return match ($this) {
            self::Mail => __('i18n.backend.enums.notifications.suppressed_channel.email'),
            self::WebPush => __('i18n.backend.enums.notifications.suppressed_channel.push_notification'),
            self::Webhook => __('i18n.backend.enums.notifications.suppressed_channel.webhook'),
            self::Schedule => __('i18n.backend.enums.notifications.suppressed_channel.schedule'),
            self::HttpRequest => __('i18n.backend.enums.notifications.suppressed_channel.http_request'),
        };
    }
}
