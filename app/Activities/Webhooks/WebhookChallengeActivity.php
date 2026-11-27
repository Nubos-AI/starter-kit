<?php

declare(strict_types=1);

namespace App\Activities\Webhooks;

use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Contracts\Webhooks\WebhookChallengeActivityInterface;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\WebhookSubscription;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;

class WebhookChallengeActivity implements WebhookChallengeActivityInterface
{
    public function __construct(
        private readonly ActivateSubscriptionViaChallengeAction $activateSubscription,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function challengeSubscription(string $tenantId, string $subscriptionId): WebhookSubscriptionStatus
    {
        return $this->steps->run(
            'webhooks.challenge-subscription',
            ['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId],
            fn (): WebhookSubscriptionStatus => $this->tenantBinder->run($tenantId, fn (): WebhookSubscriptionStatus => $this->activateSubscription->execute(
                WebhookSubscription::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($subscriptionId)
                    ->firstOrFail(),
            )),
        );
    }
}
