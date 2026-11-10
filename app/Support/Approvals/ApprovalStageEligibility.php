<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\DTOs\Governance\CandidateCircle;
use App\Enums\Approvals\ApprovalEscalationType;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Support\Governance\AbsenceDelegationResolver;
use App\Support\Governance\CandidateCircleResolver;
use Carbon\CarbonImmutable;

class ApprovalStageEligibility
{
    public function __construct(
        private readonly CandidateCircleResolver $candidateCircleResolver,
        private readonly ApprovalExclusionResolver $exclusionResolver,
        private readonly AbsenceDelegationResolver $absenceDelegationResolver,
        private readonly ApprovalRecordContext $recordContext,
        private readonly ApprovalSubjectSource $subjects,
    ) {}

    /**
     * @return list<string>
     */
    public function eligibleUserIds(ApprovalProcessStage $stage): array
    {
        return $this->resolveSets($stage, true)['eligible'];
    }

    /**
     * @return list<string>
     */
    public function candidateUserIds(ApprovalProcessStage $stage, bool $excludeAbsent): array
    {
        $process = $this->processOf($stage);

        return $this->circleUserIds(
            $stage,
            $this->definitionStageOf($stage),
            $process,
            $this->recordOf($process),
            $excludeAbsent,
        );
    }

    /**
     * @param  list<string>  $candidateIds
     * @return array<string, string>
     */
    public function availableDelegations(
        ApprovalProcessStage $stage,
        array $candidateIds,
        CarbonImmutable $at,
    ): array {
        if ($candidateIds === []) {
            return [];
        }

        $process = $this->processOf($stage);
        $record = $this->recordOf($process);
        $exclusions = $this->exclusionsOf($process);
        $gateFieldKeys = $this->gateFieldKeysOf($process);

        $delegations = [];

        foreach ($candidateIds as $candidateId) {
            $delegateId = $this->absenceDelegationResolver->delegateFor($candidateId, $at);

            if ($delegateId === null || isset($delegations[$delegateId])) {
                continue;
            }

            if ($this->exclusionResolver->isDelegateBlocked(
                $delegateId,
                $candidateId,
                $record,
                $exclusions,
                $process->triggered_by_id,
                $gateFieldKeys,
            )) {
                continue;
            }

            $delegations[$delegateId] = $candidateId;
        }

        return array_intersect_key(
            $delegations,
            array_flip($this->holdingDecisionPermission($process, array_keys($delegations))),
        );
    }

    /**
     * @return list<string>
     */
    public function notificationRecipientIds(ApprovalProcessStage $stage): array
    {
        $presentEligible = $this->resolveSets($stage, true)['eligible'];
        $fullEligible = $this->resolveSets($stage, false)['eligible'];

        $assignedId = $stage->assigned_user_id;

        if ($assignedId !== null
            && in_array($assignedId, $fullEligible, true)
            && !in_array($assignedId, $presentEligible, true)
        ) {
            $recipientIds = [];
            $absentIds = [$assignedId];
        } else {
            $recipientIds = $presentEligible;
            $absentIds = array_values(array_diff($fullEligible, $presentEligible));
        }

        if ($absentIds === []) {
            return $recipientIds;
        }

        return array_values(array_unique([
            ...$recipientIds,
            ...array_keys($this->availableDelegations($stage, $absentIds, CarbonImmutable::now())),
        ]));
    }

    public function mayDecide(string $actorId, ApprovalProcessStage $stage, ?string $onBehalfOfId): bool
    {
        if ($onBehalfOfId === null || $onBehalfOfId === '') {
            return in_array($actorId, $this->eligibleUserIds($stage), true);
        }

        if ($onBehalfOfId === $actorId) {
            return false;
        }

        if (!in_array($onBehalfOfId, $this->resolveSets($stage, false)['eligible'], true)) {
            return false;
        }

        if ($this->absenceDelegationResolver->delegateFor($onBehalfOfId, CarbonImmutable::now()) !== $actorId) {
            return false;
        }

        $process = $this->processOf($stage);

        if (!$this->holdsDecisionPermission($actorId, $process)) {
            return false;
        }
        $record = $this->recordOf($process);

        return !$this->exclusionResolver->isDelegateBlocked(
            $actorId,
            $onBehalfOfId,
            $record,
            $this->exclusionsOf($process),
            $process->triggered_by_id,
            $this->gateFieldKeysOf($process),
        );
    }

    public function escalationDelegatorFor(string $actorId, ApprovalProcessStage $stage): ?string
    {
        $delegatorId = $this->escalationDelegations($stage)[$actorId] ?? null;

        if ($delegatorId === null) {
            return null;
        }

        $sets = $this->resolveSets($stage, true);

        if (!in_array($actorId, $sets['eligible'], true) || in_array($actorId, $sets['base'], true)) {
            return null;
        }

        return $delegatorId;
    }

    public function holdsDecisionPermission(string $userId, ApprovalProcess $process): bool
    {
        return $this->holdingDecisionPermission($process, [$userId]) === [$userId];
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function usersHoldingDecisionPermission(string $anchorType, string $tenantId, array $userIds): array
    {
        $permission = $this->decisionPermissionFor($anchorType);

        if ($permission === null || $userIds === []) {
            return $userIds;
        }

        $holderIds = $this->subjects->permissionHolderIds($tenantId, $userIds, $permission);

        return array_values(array_filter(
            $userIds,
            static fn (string $userId): bool => in_array($userId, $holderIds, true),
        ));
    }

    public function exclusionsFor(ApprovalDefinition $definition, string $anchorType): ApprovalExclusionSet
    {
        $enabled = ApprovalExclusionSet::fromArray($definition->exclusions);

        if (!in_array($anchorType, $this->triggerForcedAnchorTypes(), true)) {
            return $enabled;
        }

        return new ApprovalExclusionSet(
            trigger: true,
            lastEditor: $enabled->lastEditor,
            creator: $enabled->creator,
            owner: $enabled->owner,
        );
    }

    /**
     * @return array{base: list<string>, eligible: list<string>}
     */
    private function resolveSets(ApprovalProcessStage $stage, bool $excludeAbsent): array
    {
        $process = $this->processOf($stage);
        $record = $this->recordOf($process);
        $definitionStage = $this->definitionStageOf($stage);

        $candidateIds = $this->circleUserIds(
            $stage,
            $definitionStage,
            $process,
            $record,
            $excludeAbsent,
        );

        $excludedIds = $this->exclusionResolver->excludedUserIds(
            $record,
            $this->exclusionsOf($process),
            $process->triggered_by_id,
            $this->gateFieldKeysOf($process),
        );

        $base = array_values(array_diff($candidateIds, $excludedIds));

        $eligible = array_values(array_diff(
            array_unique([
                ...$candidateIds,
                ...$this->holdingDecisionPermission($process, array_keys($this->escalationDelegations($stage))),
            ]),
            $excludedIds,
        ));

        $assignedId = $stage->assigned_user_id;

        if ($assignedId !== null && in_array($assignedId, $eligible, true)) {
            $eligible = [$assignedId];
        }

        return ['base' => $base, 'eligible' => $eligible];
    }

    /**
     * @return list<string>
     */
    private function circleUserIds(
        ApprovalProcessStage $stage,
        ApprovalDefinitionStage $definitionStage,
        ApprovalProcess $process,
        ?CustomRecord $record,
        bool $excludeAbsent,
    ): array {
        /** @var array<string, mixed> $sources */
        $sources = $definitionStage->candidate_sources;
        $tenantId = $process->tenant_id;

        return $this->holdingDecisionPermission($process, array_values(array_unique([
            ...$this->resolveCircle($record, $tenantId, $sources, $excludeAbsent),
            ...$this->escalationCircleIds($stage, $definitionStage, $record, $tenantId, $excludeAbsent),
        ])));
    }

    /**
     * @return list<string>
     */
    private function escalationCircleIds(
        ApprovalProcessStage $stage,
        ApprovalDefinitionStage $definitionStage,
        ?CustomRecord $record,
        string $tenantId,
        bool $excludeAbsent,
    ): array {
        $configured = $definitionStage->escalation_sources;

        if (!$stage->escalation_applied
            || $definitionStage->escalation_type !== ApprovalEscalationType::WidenCircle
            || !is_array($configured)
            || $configured === []
        ) {
            return [];
        }

        /** @var array<string, mixed> $sources */
        $sources = $configured;

        return $this->resolveCircle($record, $tenantId, $sources, $excludeAbsent);
    }

    /**
     * @param  array<string, mixed>  $sources
     * @return list<string>
     */
    private function resolveCircle(?CustomRecord $record, string $tenantId, array $sources, bool $excludeAbsent): array
    {
        return $this->candidateCircleResolver->resolveUserIds(
            $record,
            CandidateCircle::fromArray($sources),
            $tenantId,
            CarbonImmutable::now(),
            $excludeAbsent,
        );
    }

    /**
     * @return array<string, string>
     */
    private function escalationDelegations(ApprovalProcessStage $stage): array
    {
        return array_filter(
            $stage->escalation_added_delegations ?? [],
            static fn (string $delegatorId): bool => $delegatorId !== '',
        );
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function holdingDecisionPermission(ApprovalProcess $process, array $userIds): array
    {
        return $this->usersHoldingDecisionPermission($process->anchor_type, $process->tenant_id, $userIds);
    }

    private function decisionPermissionFor(string $anchorType): ?string
    {
        $permission = config("engine.approvals.decision_permissions.{$anchorType}");

        return is_string($permission) && $permission !== '' ? $permission : null;
    }

    /**
     * @return list<string>
     */
    private function triggerForcedAnchorTypes(): array
    {
        $configured = config('engine.approvals.trigger_forced_anchors');

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter($configured, is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function gateFieldKeysOf(ApprovalProcess $process): array
    {
        return $this->recordContext->fields($process);
    }

    private function processOf(ApprovalProcessStage $stage): ApprovalProcess
    {
        return $this->subjects->process($stage);
    }

    private function definitionOf(ApprovalProcess $process): ApprovalDefinition
    {
        return $this->subjects->definition($process);
    }

    private function definitionStageOf(ApprovalProcessStage $stage): ApprovalDefinitionStage
    {
        return $this->subjects->definitionStage($stage);
    }

    private function exclusionsOf(ApprovalProcess $process): ApprovalExclusionSet
    {
        return $this->exclusionsFor($this->definitionOf($process), $process->anchor_type);
    }

    private function recordOf(ApprovalProcess $process): ?CustomRecord
    {
        return $this->subjects->record($process);
    }
}
