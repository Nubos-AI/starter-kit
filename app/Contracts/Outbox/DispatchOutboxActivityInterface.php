<?php

declare(strict_types=1);

namespace App\Contracts\Outbox;

use App\DTOs\Outbox\OutboxClaim;
use App\DTOs\Outbox\OutboxDispatchOutcome;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Outbox\OutboxSettlementData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Outbox.')]
interface DispatchOutboxActivityInterface
{
    #[ActivityMethod(name: 'claimOutboxMessages')]
    public function claimOutboxMessages(): OutboxClaim;

    #[ActivityMethod(name: 'handleOutboxMessage')]
    public function handleOutboxMessage(string $handlerClass, OutboxMessage $message): void;

    #[ActivityMethod(name: 'settleOutboxMessages')]
    public function settleOutboxMessages(OutboxSettlementData $settlement): OutboxDispatchOutcome;
}
