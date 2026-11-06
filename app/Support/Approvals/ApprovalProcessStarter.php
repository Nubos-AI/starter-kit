<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\DTOs\Governance\CandidateCircle;
use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Approvals\ApprovalStageStatus;
use App\Exceptions\Approvals\RecordBoundCandidateCircleException;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Support\Governance\AbsenceDelegationResolver;
use App\Support\Governance\CandidateCircleResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApprovalProcessStarter
{
    public function __construct(
        private readonly CandidateCircleResolver $candidateCircleResolver,
        private readonly ApprovalExclusionResolver $exclusionResolver,
        private readonly AbsenceDelegationResolver $absenceDelegationResolver,
        protected readonly ApprovalRecordContext $recordContext,
        private readonly ApprovalEventRecorder $eventRecorder,
        private readonly ApprovalNotifier $notifier,
        protected readonly ApprovalStageEligibility $eligibility,
    ) {}

    /**
     * @throws RecordBoundCandidateCircleException
     * @throws ValidationException
     * @throws Throwable
     */
    public function startForAnchor(
        Model $anchor,
        ApprovalDefinition $definition,
        string $tenantId,
        ?string $triggeredById,
    ): ApprovalProcess {
        $this->assertCirclesIndependentOfRecord($definition);

        $definitionStage = $this->firstStage($definition);

        $this->assertAnchorApproverRemains($anchor, $definition, $definitionStage, $tenantId, $triggeredById);

        return $this->openOrCreate(
            fn (): Builder => $this->openProcessesForAnchor($tenantId, $anchor),
            [
                'tenant_id' => $tenantId,
                'record_id' => null,
                'approval_definition_id' => $definition->getKey(),
                'anchor_type' => $anchor->getMorphClass(),
                'anchor_id' => $anchor->getKey(),
                'record_version' => null,
            ],
            $definitionStage,
            $triggeredById,
        );
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function restart(ApprovalProcess $process, string $reason): ApprovalProcess
    {
        return DB::transaction(function () use ($process, $reason): ApprovalProcess {
            $locked = ApprovalProcess::query()->whereKey($process->getKey())->lockForUpdate()->firstOrFail();

            $definition = ApprovalDefinition::query()->whereKey($locked->approval_definition_id)->firstOrFail();
            $definitionStage = $this->firstStage($definition);
            $attempt = $locked->attempt + 1;

            ApprovalProcessStage::query()
                ->where('approval_process_id', $locked->getKey())
                ->where('attempt', $locked->attempt)
                ->update(['status' => ApprovalStageStatus::Superseded->value]);

            $locked->fill([
                'attempt' => $attempt,
                'current_stage_position' => $definitionStage->position,
            ])->save();

            $stage = $this->startStage($locked, $definitionStage, $attempt);

            $this->eventRecorder->record($locked, ApprovalEventType::Invalidated, [
                'stage' => $stage,
                'reason' => $reason,
            ]);

            $this->notifier->notifyStageStarted($stage);

            return $locked;
        });
    }

    public function startStage(
        ApprovalProcess $process,
        ApprovalDefinitionStage $definitionStage,
        int $attempt,
    ): ApprovalProcessStage {
        $deadlineHours = $definitionStage->deadline_hours;

        return ApprovalProcessStage::query()->create([
            'tenant_id' => $process->tenant_id,
            'approval_process_id' => $process->getKey(),
            'approval_definition_stage_id' => $definitionStage->getKey(),
            'escalation_applied' => false,
            'position' => $definitionStage->position,
            'attempt' => $attempt,
            'status' => ApprovalStageStatus::Pending,
            'deadline_at' => $deadlineHours === null
                ? null
                : CarbonImmutable::now('UTC')->addHours($deadlineHours),
            'started_at' => now(),
        ]);
    }

    /**
     * @param  Closure(): Builder<ApprovalProcess>  $openProcesses
     * @param  array<string, mixed>  $attributes
     *
     * @throws Throwable
     */
    protected function openOrCreate(
        Closure $openProcesses,
        array $attributes,
        ApprovalDefinitionStage $definitionStage,
        ?string $triggeredById,
    ): ApprovalProcess {
        try {
            return DB::transaction(function () use (
                $openProcesses,
                $attributes,
                $definitionStage,
                $triggeredById,
            ): ApprovalProcess {
                $open = $openProcesses()->lockForUpdate()->first();

                if ($open instanceof ApprovalProcess) {
                    return $open;
                }

                $process = ApprovalProcess::query()->create([
                    ...$attributes,
                    'triggered_by_id' => $triggeredById,
                    'status' => ApprovalProcessStatus::Pending,
                    'attempt' => 1,
                    'current_stage_position' => $definitionStage->position,
                    'started_at' => now(),
                ]);

                $stage = $this->startStage($process, $definitionStage, 1);

                $this->eventRecorder->record($process, ApprovalEventType::Started, [
                    'actor_id' => $triggeredById,
                ]);

                $this->eventRecorder->record($process, ApprovalEventType::StageStarted, [
                    'stage' => $stage,
                ]);

                $this->notifier->notifyStageStarted($stage);

                return $process;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $open = $openProcesses()->first();

            if (!$open instanceof ApprovalProcess) {
                throw $exception;
            }

            return $open;
        }
    }

    /**
     * @throws ValidationException
     */
    protected function firstStage(ApprovalDefinition $definition): ApprovalDefinitionStage
    {
        $stage = $definition->stages()->orderBy('position')->first();

        if (!$stage instanceof ApprovalDefinitionStage) {
            throw ValidationException::withMessages([
                'status' => __('i18n.backend.support.approvals.approval_process_starter.the_approval_configured_for_this_transition_has_no_stage'),
            ]);
        }

        return $stage;
    }

    /**
     * @throws RecordBoundCandidateCircleException
     */
    private function assertCirclesIndependentOfRecord(ApprovalDefinition $definition): void
    {
        $recordBoundStage = $definition->stages()
            ->orderBy('position')
            ->get()
            ->first(fn (ApprovalDefinitionStage $definitionStage): bool => $this->isRecordBound($definitionStage));

        if ($recordBoundStage instanceof ApprovalDefinitionStage) {
            throw RecordBoundCandidateCircleException::forStage($recordBoundStage->position);
        }
    }

    private function isRecordBound(ApprovalDefinitionStage $definitionStage): bool
    {
        return collect([$definitionStage->candidate_sources, $definitionStage->escalation_sources])
            ->filter(static fn (mixed $sources): bool => is_array($sources) && $sources !== [])
            ->contains(static function (mixed $sources): bool {
                /** @var array<string, mixed> $sources */
                return CandidateCircle::fromArray($sources)->dependsOnRecord();
            });
    }

    /**
     * @throws ValidationException
     */
    private function assertAnchorApproverRemains(
        Model $anchor,
        ApprovalDefinition $definition,
        ApprovalDefinitionStage $definitionStage,
        string $tenantId,
        ?string $triggeredById,
    ): void {
        if ($this->hasRemainingApprover(
            null,
            $anchor->getMorphClass(),
            $tenantId,
            $definitionStage,
            $this->eligibility->exclusionsFor($definition, $anchor->getMorphClass()),
            $triggeredById,
            [],
        )) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __('i18n.backend.support.approvals.approval_process_starter.after_exclusions_and_absences_no_one_remains_who_can'),
        ]);
    }

    /**
     * @param  list<string>  $gateFieldKeys
     */
    protected function hasRemainingApprover(
        ?CustomRecord $record,
        string $anchorType,
        string $tenantId,
        ApprovalDefinitionStage $definitionStage,
        ApprovalExclusionSet $exclusions,
        ?string $triggeredById,
        array $gateFieldKeys,
    ): bool {
        /** @var array<string, mixed> $sources */
        $sources = $definitionStage->candidate_sources;

        $circle = CandidateCircle::fromArray($sources);
        $at = CarbonImmutable::now();

        $excludedIds = $this->exclusionResolver->excludedUserIds(
            $record,
            $exclusions,
            $triggeredById,
            $gateFieldKeys,
        );

        $presentIds = $this->eligibility->usersHoldingDecisionPermission(
            $anchorType,
            $tenantId,
            $this->candidateCircleResolver->resolveUserIds($record, $circle, $tenantId, $at, true),
        );

        if (array_diff($presentIds, $excludedIds) !== []) {
            return true;
        }

        $absentIds = array_diff(
            $this->eligibility->usersHoldingDecisionPermission(
                $anchorType,
                $tenantId,
                $this->candidateCircleResolver->resolveUserIds($record, $circle, $tenantId, $at, false),
            ),
            $presentIds,
        );

        foreach (array_diff($absentIds, $excludedIds) as $absentId) {
            $delegateId = $this->absenceDelegationResolver->delegateFor($absentId, $at);

            if ($delegateId === null || $this->eligibility->usersHoldingDecisionPermission($anchorType, $tenantId, [$delegateId]) === []) {
                continue;
            }

            if ($this->exclusionResolver->isDelegateBlocked(
                $delegateId,
                $absentId,
                $record,
                $exclusions,
                $triggeredById,
                $gateFieldKeys,
            )) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return Builder<ApprovalProcess>
     */
    private function openProcessesForAnchor(string $tenantId, Model $anchor): Builder
    {
        return ApprovalProcess::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('record_id')
            ->where('anchor_type', $anchor->getMorphClass())
            ->where('anchor_id', $anchor->getKey())
            ->where('status', ApprovalProcessStatus::Pending);
    }
}
