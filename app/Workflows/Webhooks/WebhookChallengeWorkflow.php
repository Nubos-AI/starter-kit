<?php

declare(strict_types=1);

namespace App\Workflows\Webhooks;

use App\Contracts\Webhooks\WebhookChallengeActivityInterface;
use App\Contracts\Webhooks\WebhookChallengeWorkflowInterface;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Traits\Temporal\RunsProcessSteps;
use Generator;
use Temporal\Workflow;
use Throwable;

class WebhookChallengeWorkflow implements WebhookChallengeWorkflowInterface
{
    use RunsProcessSteps;

    private ?WebhookSubscriptionStatus $status = null;

    private ?Throwable $failure = null;

    public function run(string $tenantId, string $subscriptionId): Generator
    {
        $context = $this->processLogContext(['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId]);

        try {
            $status = yield $this->processSteps(WebhookChallengeActivityInterface::class)->challengeSubscription($tenantId, $subscriptionId);
        } catch (Throwable $failure) {
            $this->failure = $failure;

            Workflow::getLogger()->error('Webhook challenge failed.', [...$context, 'error' => $this->failureMessage($failure)]);

            yield Workflow::await(static fn (): bool => Workflow::allHandlersFinished());

            throw $failure;
        }

        $this->status = $status instanceof WebhookSubscriptionStatus ? $status : WebhookSubscriptionStatus::Pending;

        Workflow::getLogger()->info('Webhook challenge finished.', [...$context, 'status' => $this->status->value]);

        yield Workflow::await(static fn (): bool => Workflow::allHandlersFinished());

        return $this->status;
    }

    public function challengeOutcome(): Generator
    {
        yield Workflow::await(fn (): bool => $this->status instanceof WebhookSubscriptionStatus || $this->failure instanceof Throwable);

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $this->status;
    }
}
