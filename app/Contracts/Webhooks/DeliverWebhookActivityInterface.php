<?php

declare(strict_types=1);

namespace App\Contracts\Webhooks;

use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\DeliveryOutcome;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'webhook.')]
interface DeliverWebhookActivityInterface
{
    #[ActivityMethod(name: 'deliverWebhook')]
    public function deliverWebhook(WebhookDeliveryData $input): DeliveryOutcome;

    #[ActivityMethod(name: 'recordDeliveryFailure')]
    public function recordDeliveryFailure(string $subscriptionId, string $tenantId, string $eventId): void;
}
