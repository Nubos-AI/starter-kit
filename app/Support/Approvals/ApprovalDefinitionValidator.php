<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalExclusion;
use App\Enums\Approvals\ApprovalQuorumType;
use App\Enums\Engine\SystemFilterField;
use App\Enums\Governance\CandidateSource;
use App\Exceptions\Approvals\RecordBoundCandidateCircleException;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use App\Support\Governance\CandidateCircleResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class ApprovalDefinitionValidator
{
    private int $minimumQuorumCount = 2;

    private string $deadEndStageWarningTemplate = 'i18n.backend.support.approvals.approval_definition_validator.stage_d_specifies_exactly_one_eligible_person_and_also';

    public function __construct(
        private readonly CandidateCircleResolver $candidateCircleResolver,
        private readonly ApprovalStageEligibility $eligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertAnchorConfiguration(array $validated): void
    {
        $this->assertStages($validated['stages'] ?? [], false);
    }

    /**
     * @return list<int>
     */
    public function detectDeadEndStagePositions(ApprovalDefinition $definition): array
    {
        $exclusions = $this->eligibility->exclusionsFor($definition, $definition->anchor_type);

        if (!$exclusions->includes(ApprovalExclusion::Owner) && !$exclusions->includes(ApprovalExclusion::Trigger)) {
            return [];
        }

        $positions = [];

        foreach ($definition->stages->sortBy('position') as $stage) {
            if (!$this->isDeadEndProne($stage)) {
                continue;
            }

            $positions[] = $stage->position;
        }

        return $positions;
    }

    /**
     * @return list<string>
     */
    public function deadEndStageWarnings(ApprovalDefinition $definition, string $subject): array
    {
        return array_map(
            fn (int $position): string => sprintf(__($this->deadEndStageWarningTemplate), $position, $subject),
            $this->detectDeadEndStagePositions($definition),
        );
    }

    /**
     * @throws ValidationException
     */
    protected function assertStages(mixed $stages, bool $allowsRecordBoundCircles): void
    {
        if (!is_array($stages) || $stages === []) {
            throw ValidationException::withMessages([
                'stages' => __('i18n.backend.support.approvals.approval_definition_validator.an_approval_requires_at_least_one_stage'),
            ]);
        }

        foreach (array_values($stages) as $index => $stage) {
            $this->assertStage($index, is_array($stage) ? $stage : [], $allowsRecordBoundCircles);
        }
    }

    /**
     * @param  array<string, mixed>  $stage
     *
     * @throws ValidationException
     */
    private function assertStage(int $index, array $stage, bool $allowsRecordBoundCircles): void
    {
        $this->assertQuorum($index, $stage);
        $this->assertDeadline($index, $stage);
        $this->assertCandidateSources($index, $stage, $allowsRecordBoundCircles);
        $this->assertEscalation($index, $stage, $allowsRecordBoundCircles);
    }

    /**
     * @param  array<string, mixed>  $stage
     *
     * @throws ValidationException
     */
    private function assertQuorum(int $index, array $stage): void
    {
        $quorumType = $stage['quorum_type'] ?? null;
        $quorumCount = $stage['quorum_count'] ?? null;

        if ($quorumType === ApprovalQuorumType::AtLeastN->value) {
            if (!is_int($quorumCount) || $quorumCount < $this->minimumQuorumCount) {
                throw ValidationException::withMessages([
                    "stages.{$index}.quorum_count" => __('i18n.backend.support.approvals.approval_definition_validator.a_minimum_count_quorum_requires_a_count_of_at', ['value1' => $this->minimumQuorumCount]),
                ]);
            }

            $this->assertQuorumIsReachable($index, $stage, $quorumCount);

            return;
        }

        if ($quorumCount !== null) {
            throw ValidationException::withMessages([
                "stages.{$index}.quorum_count" => __('i18n.backend.support.approvals.approval_definition_validator.only_a_minimum_count_quorum_may_specify_a_count'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $stage
     *
     * @throws ValidationException
     */
    private function assertQuorumIsReachable(int $index, array $stage, int $quorumCount): void
    {
        $tenantId = TenantContext::currentId();
        $circle = $stage['candidate_sources'] ?? null;

        if ($tenantId === null || !is_array($circle)) {
            return;
        }

        $available = $this->candidateCircleResolver->countWithoutRecord(
            CandidateCircle::fromArray($circle),
            $tenantId,
        );

        if ($available === null || $available >= $quorumCount) {
            return;
        }

        throw ValidationException::withMessages([
            "stages.{$index}.quorum_count" => __('i18n.backend.support.approvals.approval_definition_validator.a_minimum_count_quorum_of_cannot_be_reached_with', ['value1' => $quorumCount, 'value2' => $available]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stage
     *
     * @throws ValidationException
     */
    private function assertDeadline(int $index, array $stage): void
    {
        $deadlineHours = $stage['deadline_hours'] ?? null;

        if ($deadlineHours === null) {
            return;
        }

        if (!is_int($deadlineHours) || $deadlineHours < 1) {
            throw ValidationException::withMessages([
                "stages.{$index}.deadline_hours" => __('i18n.backend.support.approvals.approval_definition_validator.a_deadline_is_measured_in_whole_hours_and_must'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $stage
     *
     * @throws ValidationException
     */
    private function assertCandidateSources(int $index, array $stage, bool $allowsRecordBoundCircles): void
    {
        $circle = $stage['candidate_sources'] ?? null;

        if (!is_array($circle)) {
            throw ValidationException::withMessages([
                "stages.{$index}.candidate_sources" => __('i18n.backend.support.approvals.approval_definition_validator.every_stage_requires_a_pool_of_potential_approvers'),
            ]);
        }

        $key = "stages.{$index}.candidate_sources";
        $candidateCircle = CandidateCircle::fromArray($circle);

        $this->assertIndependentOfRecord($key, $index, $candidateCircle, $allowsRecordBoundCircles);
        $this->assertCircle($key, $candidateCircle);
    }

    /**
     * @param  array<string, mixed>  $stage
     *
     * @throws ValidationException
     */
    private function assertEscalation(int $index, array $stage, bool $allowsRecordBoundCircles): void
    {
        $escalationType = $stage['escalation_type'] ?? null;
        $escalationSources = $stage['escalation_sources'] ?? null;
        $key = "stages.{$index}.escalation_sources";

        if ($escalationType !== ApprovalEscalationType::WidenCircle->value) {
            if ($escalationSources !== null) {
                throw ValidationException::withMessages([
                    $key => __('i18n.backend.support.approvals.approval_definition_validator.a_fallback_pool_only_applies_to_an_expanding_escalation'),
                ]);
            }

            return;
        }

        if (!is_array($escalationSources)) {
            throw ValidationException::withMessages([
                $key => __('i18n.backend.support.approvals.approval_definition_validator.an_expanding_escalation_requires_a_fallback_pool'),
            ]);
        }

        $escalationCircle = CandidateCircle::fromArray($escalationSources);

        $this->assertIndependentOfRecord($key, $index, $escalationCircle, $allowsRecordBoundCircles);
        $this->assertCircle($key, $escalationCircle);
    }

    /**
     * @throws ValidationException
     */
    private function assertIndependentOfRecord(
        string $key,
        int $index,
        CandidateCircle $circle,
        bool $allowsRecordBoundCircles,
    ): void {
        if ($allowsRecordBoundCircles || !$circle->dependsOnRecord()) {
            return;
        }

        throw ValidationException::withMessages([
            $key => RecordBoundCandidateCircleException::forStage($index + 1)->getMessage(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertCircle(string $key, CandidateCircle $circle): void
    {
        $sources = $this->effectiveSources($circle);

        if ($sources === []) {
            throw ValidationException::withMessages([
                $key => __('i18n.backend.support.approvals.approval_definition_validator.a_pool_of_potential_approvers_requires_at_least_one'),
            ]);
        }

        if (
            in_array(CandidateSource::Field, $sources, true)
            && $circle->fieldKey !== SystemFilterField::Owner->value
        ) {
            throw ValidationException::withMessages([
                $key => __('i18n.backend.support.approvals.approval_definition_validator.a_pool_of_potential_approvers_can_only_be_formed'),
            ]);
        }
    }

    private function isDeadEndProne(ApprovalDefinitionStage $stage): bool
    {
        /** @var array<string, mixed> $sources */
        $sources = $stage->candidate_sources;

        $circle = CandidateCircle::fromArray($sources);

        return $this->effectiveSources($circle) === [CandidateSource::FixedList]
            && count($circle->userIds) === 1;
    }

    /**
     * @return list<CandidateSource>
     */
    private function effectiveSources(CandidateCircle $circle): array
    {
        $sources = [];

        if ($circle->hasSource(CandidateSource::Role) && $circle->roleIds !== []) {
            $sources[] = CandidateSource::Role;
        }

        if ($circle->hasSource(CandidateSource::Team) && ($circle->teamIds !== [] || $circle->includeRecordTeam)) {
            $sources[] = CandidateSource::Team;
        }

        if ($circle->hasSource(CandidateSource::Field) && $circle->fieldKey !== null) {
            $sources[] = CandidateSource::Field;
        }

        if ($circle->hasSource(CandidateSource::FixedList) && $circle->userIds !== []) {
            $sources[] = CandidateSource::FixedList;
        }

        return $sources;
    }
}
