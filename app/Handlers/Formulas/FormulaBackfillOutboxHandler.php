<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\DTOs\Formulas\FormulaBackfillData;
use App\DTOs\Outbox\OutboxMessage;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Formulas\FormulaBackfillProgress;
use App\Support\Formulas\FormulaBackfillStarter;
use Illuminate\Support\Facades\Log;

class FormulaBackfillOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(
        private readonly FormulaBackfillStarter $starter,
        private readonly FormulaBackfillProgress $progress,
    ) {}

    public function topic(): string
    {
        return OutboxTopic::FormulaBackfillRequested->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $input = FormulaBackfillData::fromArray([...$message->payload, 'tenant_id' => $message->tenantId, 'backfill_run_id' => $message->subject->id]);

        if (!$this->progress->statusOf($input->tenantId, $input->backfillRunId)?->isOpen()) {
            Log::info('Formula backfill start skipped because its run is no longer open.', ['tenant_id' => $input->tenantId, 'backfill_run_id' => $input->backfillRunId]);

            return;
        }

        $this->starter->start($input);
    }
}
