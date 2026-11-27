<?php

declare(strict_types=1);

namespace App\Activities\Outbox;

use App\Actions\Outbox\ClaimOutboxMessagesAction;
use App\Actions\Outbox\HandleOutboxMessageAction;
use App\Actions\Outbox\SettleOutboxMessagesAction;
use App\Contracts\Outbox\DispatchOutboxActivityInterface;
use App\DTOs\Outbox\OutboxClaim;
use App\DTOs\Outbox\OutboxDispatchOutcome;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Outbox\OutboxSettlementData;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;

class DispatchOutboxActivity implements DispatchOutboxActivityInterface
{
    public function __construct(
        private readonly ClaimOutboxMessagesAction $claimMessages,
        private readonly HandleOutboxMessageAction $handleMessage,
        private readonly SettleOutboxMessagesAction $settleMessages,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function claimOutboxMessages(): OutboxClaim
    {
        return $this->steps->run('outbox.claim', [], fn (): OutboxClaim => $this->claimMessages->execute());
    }

    public function handleOutboxMessage(string $handlerClass, OutboxMessage $message): void
    {
        $this->steps->run(
            'outbox.handle',
            [
                'handler' => $handlerClass,
                'topic' => $message->topic,
                'tenant_id' => $message->tenantId,
                'subject_type' => $message->subject->type,
                'subject_id' => $message->subject->id,
                'attempts' => $message->attempts,
            ],
            fn () => $this->tenantBinder->run($message->tenantId, fn () => $this->handleMessage->execute($handlerClass, $message)),
        );
    }

    public function settleOutboxMessages(OutboxSettlementData $settlement): OutboxDispatchOutcome
    {
        return $this->steps->run(
            'outbox.settle',
            ['handled' => count($settlement->handledIds), 'failed' => count($settlement->failedDeliveries), 'released' => count($settlement->releasedIds)],
            fn (): OutboxDispatchOutcome => $this->settleMessages->execute($settlement),
        );
    }
}
