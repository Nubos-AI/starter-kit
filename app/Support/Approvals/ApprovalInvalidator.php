<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\CustomRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApprovalInvalidator
{
    public function __construct(
        private readonly ApprovalRecordContext $recordContext,
        private readonly ApprovalProcessStarter $processStarter,
        private readonly ApprovalEventRecorder $eventRecorder,
        private readonly ApprovalSubjectSource $subjects,
    ) {}

    /**
     * @throws Throwable
     */
    public function cancelOpen(CustomRecord $record, string $reason): void
    {
        $processes = $this->subjects->lockedPendingProcessesFor($record);

        foreach ($processes as $process) {
            $process->fill([
                'status' => ApprovalProcessStatus::Cancelled,
                'cancellation_reason' => $reason,
                'finished_at' => now(),
            ])->save();

            $this->eventRecorder->record($process, ApprovalEventType::Cancelled, [
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @param  list<string>  $changedFieldKeys
     *
     * @throws Throwable
     */
    public function handleChange(CustomRecord $record, array $changedFieldKeys): void
    {
        if ($changedFieldKeys === []) {
            return;
        }

        $processes = $this->subjects->lockedPendingProcessesFor($record);

        foreach ($processes as $process) {
            $triggeringKeys = array_values(
                array_intersect($this->recordContext->fields($process, true), $changedFieldKeys),
            );

            if ($triggeringKeys === []) {
                continue;
            }

            $reason = __('i18n.backend.support.approvals.approval_invalidator.invalidated_by_changes_to').implode(', ', $triggeringKeys).'.';

            try {
                $this->processStarter->restart($process, $reason);
            } catch (ValidationException $exception) {
                Log::error('An approval process could not be restarted after a gate field changed.', [
                    'tenant_id' => $record->tenant_id,
                    'record_id' => (string) $record->getKey(),
                    'approval_process_id' => (string) $process->getKey(),
                    'reason' => $reason,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
