<?php

declare(strict_types=1);

namespace App\Activities\Webhooks;

use App\Contracts\Webhooks\DispatchWebhookDeliveriesActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Webhooks\WebhookChangeDispatcher;

class DispatchWebhookDeliveriesActivity implements DispatchWebhookDeliveriesActivityInterface
{
    public function __construct(private readonly WebhookChangeDispatcher $dispatcher) {}

    public function dispatchWebhooks(RecordChangeBatch $changes): int
    {
        foreach ($changes->changes as $change) {
            $this->dispatcher->dispatch(
                $change->tenantId,
                $change->objectTypeId,
                $change->recordId,
                $change->version,
                $change->sequence,
                $change->changedFieldKeys,
            );
        }

        return $changes->count();
    }
}
