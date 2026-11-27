<?php

declare(strict_types=1);

namespace App\Handlers\Engine;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\DTOs\Engine\RecordBackfillData;
use App\DTOs\Outbox\OutboxMessage;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Engine\RecordBackfillProgress;
use App\Support\Engine\RecordBackfillStarter;
use Illuminate\Support\Facades\Log;

class RecordBackfillOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(
        private readonly RecordBackfillStarter $starter,
        private readonly RecordBackfillProgress $progress,
    ) {}

    public function topic(): string
    {
        return OutboxTopic::RecordBackfillRequested->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $input = RecordBackfillData::fromArray([...$message->payload, 'tenant_id' => $message->tenantId, 'backfill_run_id' => $message->subject->id]);

        if (!$this->progress->statusOf($input->tenantId, $input->backfillRunId)?->isOpen()) {
            Log::info('Record backfill start skipped because its run is no longer open.', ['tenant_id' => $input->tenantId, 'backfill_run_id' => $input->backfillRunId]);

            return;
        }

        $this->starter->start($input);
    }
}
