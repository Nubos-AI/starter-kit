<?php

declare(strict_types=1);

namespace App\Actions\Routing;

use App\DTOs\Routing\AssignmentOutcome;
use App\DTOs\Routing\AssignmentRequest;
use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Routing\AssignmentFailureReason;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Approvals\ApprovalEventRecorder;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Routing\AssignmentSelector;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssignApprovalStageAction
{
    public function __construct(
        private readonly AssignmentSelector $assignmentSelector,
        private readonly ApprovalStageEligibility $approvalStageEligibility,
        private readonly ApprovalEventRecorder $approvalEventRecorder,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record, AssignmentRequest $request): AssignmentOutcome
    {
        $process = ApprovalProcess::query()
            ->where('record_id', $record->getKey())
            ->where('status', ApprovalProcessStatus::Pending)
            ->orderByDesc('started_at')
            ->first();

        if (!$process instanceof ApprovalProcess) {
            return AssignmentOutcome::failed(AssignmentFailureReason::NoOpenProcess);
        }

        $stage = $process->currentStage();

        if (!$stage instanceof ApprovalProcessStage) {
            return AssignmentOutcome::failed(AssignmentFailureReason::NoCurrentStage);
        }

        if ($stage->assigned_user_id !== null) {
            return AssignmentOutcome::failed(AssignmentFailureReason::AlreadyAssigned);
        }

        $eligibleUserIds = $this->approvalStageEligibility->eligibleUserIds($stage);

        if ($eligibleUserIds === []) {
            return AssignmentOutcome::failed(AssignmentFailureReason::NoEligibleApprover);
        }

        $outcome = $this->assignmentSelector->select($record, $request, $eligibleUserIds);
        $candidate = $outcome->user;

        if (!$candidate instanceof User) {
            return $outcome;
        }

        DB::transaction(function () use ($process, $stage, $candidate, $request): void {
            $stage->fill(['assigned_user_id' => (string) $candidate->getKey()])->save();

            $this->approvalEventRecorder->record($process, ApprovalEventType::Assigned, [
                'stage' => $stage,
                'payload' => [
                    'assignedUserId' => (string) $candidate->getKey(),
                    'automationId' => $request->automationId,
                    'nodeId' => $request->nodeId,
                ],
            ]);
        });

        return $outcome;
    }
}
