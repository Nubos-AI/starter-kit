<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalEscalationType;
use App\Models\ApprovalProcessStage;
use Carbon\CarbonImmutable;

class ApprovalEscalationResolver
{
    public function __construct(private readonly ApprovalStageEligibility $eligibility) {}

    public function configuredTypeFor(ApprovalProcessStage $stage): ApprovalEscalationType
    {
        return $stage->definitionStage()->firstOrFail()->escalation_type ?? ApprovalEscalationType::Delegate;
    }

    public function apply(ApprovalProcessStage $stage, CarbonImmutable $at): ApprovalEscalationType
    {
        $configured = $this->configuredTypeFor($stage);

        if ($configured !== ApprovalEscalationType::Delegate) {
            return $configured;
        }

        $delegations = $this->resolveDelegations($stage, $at);

        if ($delegations === []) {
            return ApprovalEscalationType::NotifyAgain;
        }

        $stage->fill(['escalation_added_delegations' => $delegations]);

        return ApprovalEscalationType::Delegate;
    }

    /**
     * @return array<string, string>
     */
    private function resolveDelegations(ApprovalProcessStage $stage, CarbonImmutable $at): array
    {
        $candidateIds = $this->eligibility->candidateUserIds($stage, false);

        return array_diff_key(
            $this->eligibility->availableDelegations($stage, $candidateIds, $at),
            array_flip($candidateIds),
        );
    }
}
