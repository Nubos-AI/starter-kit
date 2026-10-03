<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Abstracts\EngineNotification;

class WebhookNotification extends EngineNotification
{
    public function __construct(
        private readonly string $subscriptionId,
        private readonly string $targetUrl,
        private readonly int $consecutiveFailures,
    ) {}

    public function type(): string
    {
        return 'webhook.disabled';
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        return [
            'title' => __('i18n.backend.notifications.webhook_notification.webhook_subscription_disabled'),
            'body' => __('i18n.backend.notifications.webhook_notification.delivery_to_has_failed_times_in_a_row_the', ['value1' => $this->targetUrl, 'value2' => $this->consecutiveFailures]),
            'subscriptionId' => $this->subscriptionId,
        ];
    }
}
