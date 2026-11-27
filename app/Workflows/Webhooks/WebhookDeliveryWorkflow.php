<?php

declare(strict_types=1);

namespace App\Workflows\Webhooks;

use App\Contracts\Webhooks\DeliverWebhookActivityInterface;
use App\Contracts\Webhooks\WebhookDeliveryWorkflowInterface;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\DeliveryOutcome;
use App\Support\Webhooks\WebhookRetryOptions;
use App\Traits\Temporal\RunsProcessSteps;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;
use Throwable;

class WebhookDeliveryWorkflow implements WebhookDeliveryWorkflowInterface
{
    use RunsProcessSteps;

    public function deliver(WebhookDeliveryData $input): Generator
    {
        $context = $this->processLogContext(['tenant_id' => $input->tenantId, 'subscription_id' => $input->subscriptionId, 'event_id' => $input->eventId]);

        $delivery = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::seconds((int) config('webhooks.delivery.start_to_close_timeout')))
            ->withRetryOptions(WebhookRetryOptions::make())
            ->build(DeliverWebhookActivityInterface::class);

        try {
            $outcome = yield $delivery->deliverWebhook($input);
        } catch (ActivityFailure $failure) {
            Workflow::getLogger()->warning('Webhook delivery gave up after its retries.', [...$context, 'error' => $this->failureMessage($failure)]);

            yield from $this->recordFailure($input, false);

            throw $failure;
        }

        try {
            yield from $this->settle($input, $outcome);
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Webhook delivery could not settle its outcome.', [...$context, 'error' => $this->failureMessage($failure)]);

            throw $failure;
        }

        Workflow::getLogger()->info('Webhook delivery finished.', [...$context, 'outcome' => $outcome instanceof DeliveryOutcome ? $outcome->value : null]);

        return $outcome;
    }

    private function settle(WebhookDeliveryData $input, mixed $outcome): Generator
    {
        if ($outcome === DeliveryOutcome::Delivered) {
            yield $this->processSteps(DeliverWebhookActivityInterface::class)->resetDeliveryFailures($input->subscriptionId, $input->tenantId);

            return;
        }

        if ($outcome === DeliveryOutcome::Parked || $outcome === DeliveryOutcome::Gone) {
            yield from $this->recordFailure($input, $outcome === DeliveryOutcome::Gone);
        }
    }

    private function recordFailure(WebhookDeliveryData $input, bool $isPermanent): Generator
    {
        $steps = $this->processSteps(DeliverWebhookActivityInterface::class);

        $isDisabled = yield $steps->recordDeliveryFailure($input->subscriptionId, $input->tenantId, $isPermanent);

        if ($isDisabled === true) {
            yield $steps->notifySubscriptionDisabled($input->subscriptionId, $input->tenantId, $this->processRunId());
        }
    }
}
