<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RecordChangeRelayWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Temporal\ProcessStartData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Temporal\Common\IdReusePolicy;
use Throwable;

class RecordChangeRelayStarter
{
    /**
     * @var non-empty-string
     */
    private string $workflowId = 'automation-relay-immediate';

    public function __construct(
        private readonly ProcessStarterInterface $processes,
        private readonly RecordChangeRelayRequest $request,
    ) {}

    public function startAfterCommit(): void
    {
        $this->request->request();

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->startWhenSettled());
            DB::afterRollBack(fn () => $this->discardAfterRollback());

            return;
        }

        $this->startWhenSettled();
    }

    public function startNow(): void
    {
        if (!config('record-processing.schedules.relay_immediate')) {
            return;
        }

        try {
            $this->processes->start(new ProcessStartData(
                workflow: RecordChangeRelayWorkflowInterface::class,
                workflowId: $this->workflowId,
                reusePolicy: IdReusePolicy::AllowDuplicate,
            ));
        } catch (Throwable $throwable) {
            Log::warning('Immediate automation relay start failed; the schedule backstop will drain the outbox.', [
                'reason' => $throwable->getMessage(),
            ]);
        }
    }

    private function startWhenSettled(): void
    {
        if (DB::transactionLevel() > 0 || !$this->request->take()) {
            return;
        }

        $this->startNow();
    }

    private function discardAfterRollback(): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        $this->request->discard();
    }
}
