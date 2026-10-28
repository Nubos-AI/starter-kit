<?php

declare(strict_types=1);

namespace App\Workflows\Webhooks;

use App\Contracts\Webhooks\DeliverWebhookActivityInterface;
use App\Contracts\Webhooks\WebhookDeliveryWorkflowInterface;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Support\Webhooks\WebhookRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Exception\Failure\ActivityFailure;

class WebhookDeliveryWorkflow implements WebhookDeliveryWorkflowInterface
{
    public function deliver(WebhookDeliveryData $input): Generator
    {
        $delivery = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::seconds((int) config('webhooks.delivery.start_to_close_timeout')))
            ->withRetryOptions(WebhookRetryOptions::make())
            ->build(DeliverWebhookActivityInterface::class);

        try {
            return yield $delivery->deliverWebhook($input);
        } catch (ActivityFailure $exception) {
            yield $delivery->recordDeliveryFailure(
                $input->subscriptionId,
                $input->tenantId,
                $input->eventId,
            );

            throw $exception;
        }
    }
}
