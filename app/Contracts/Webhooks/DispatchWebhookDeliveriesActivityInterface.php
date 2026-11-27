<?php

declare(strict_types=1);

namespace App\Contracts\Webhooks;

use App\DTOs\Engine\RecordChangeBatch;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'WebhookDispatch.')]
interface DispatchWebhookDeliveriesActivityInterface
{
    #[ActivityMethod(name: 'dispatchWebhooks')]
    public function dispatchWebhooks(RecordChangeBatch $changes): int;
}
