<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalQuorumType;
use App\Models\ApprovalProcessStage;

class ApprovalQuorumEvaluator
{
    public function __construct(
        private readonly ApprovalStageEligibility $eligibility,
        private readonly ApprovalSubjectSource $subjects,
    ) {}

    public function isSatisfied(ApprovalProcessStage $stage): bool
    {
        return $this->outstanding($stage) === 0;
    }

    public function outstanding(ApprovalProcessStage $stage): int
    {
        return max(0, $this->required($stage) - $this->approvalCount($stage));
    }

    private function required(ApprovalProcessStage $stage): int
    {
        $definitionStage = $this->subjects->definitionStage($stage);

        return match ($definitionStage->quorum_type) {
            ApprovalQuorumType::Any => 1,
            ApprovalQuorumType::AtLeastN => max(1, $definitionStage->quorum_count ?? 1),
            ApprovalQuorumType::All => max(1, count($this->eligibility->eligibleUserIds($stage))),
        };
    }

    private function approvalCount(ApprovalProcessStage $stage): int
    {
        return $this->subjects->approvedDecisionCount($stage);
    }
}
