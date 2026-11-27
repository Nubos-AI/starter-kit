<?php

declare(strict_types=1);

namespace App\Handlers\Promotion;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Contracts\Promotion\PromotionDispatcherInterface;
use App\DTOs\Outbox\OutboxMessage;
use App\Enums\Outbox\OutboxTopic;
use App\Models\PromotionRun;

class PromotionApprovedOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(private readonly PromotionDispatcherInterface $dispatcher) {}

    public function topic(): string
    {
        return OutboxTopic::PromotionApproved->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $run = PromotionRun::withoutTenantScope()
            ->where('tenant_id', $message->tenantId)
            ->whereKey($message->subject->id)
            ->firstOrFail();

        $this->dispatcher->start($run);
    }
}
