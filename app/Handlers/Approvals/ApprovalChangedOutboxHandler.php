<?php

declare(strict_types=1);

namespace App\Handlers\Approvals;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Approvals\ApprovalProcessData;
use App\DTOs\Outbox\OutboxMessage;
use App\Enums\Outbox\OutboxTopic;

class ApprovalChangedOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function topic(): string
    {
        return OutboxTopic::ApprovalChanged->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $this->processes->startWithSignal(
            (new ApprovalProcessData($message->tenantId, $message->subject->id))->processStart(),
            'stageChanged',
        );
    }
}
