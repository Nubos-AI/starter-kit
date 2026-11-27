<?php

declare(strict_types=1);

namespace App\Contracts\Approvals;

use App\DTOs\Approvals\ApprovalCommandData;
use App\DTOs\Approvals\ApprovalEscalationResultData;
use App\DTOs\Approvals\ApprovalProcessData;
use App\DTOs\Approvals\ApprovalProcessStateData;
use App\DTOs\Approvals\ApprovalStepResultData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'approvals.')]
interface ApprovalProcessActivityInterface
{
    #[ActivityMethod(name: 'describeProcess')]
    public function describeProcess(ApprovalProcessData $input): ApprovalProcessStateData;

    #[ActivityMethod(name: 'decide')]
    public function decide(ApprovalCommandData $command): ApprovalStepResultData;

    #[ActivityMethod(name: 'cancel')]
    public function cancel(ApprovalCommandData $command): ApprovalStepResultData;

    #[ActivityMethod(name: 'notifyStageStarted')]
    public function notifyStageStarted(string $tenantId, string $stageId): void;

    #[ActivityMethod(name: 'escalateStage')]
    public function escalateStage(string $tenantId, string $stageId): ApprovalEscalationResultData;

    #[ActivityMethod(name: 'notifyEscalation')]
    public function notifyEscalation(string $tenantId, string $stageId, string $escalationType): void;

    #[ActivityMethod(name: 'notifyDecision')]
    public function notifyDecision(string $tenantId, string $eventId): void;
}
