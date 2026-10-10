<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Approvals\ApprovalStageStatus;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\User;
use App\Support\Approvals\ApprovalEventRecorder;
use App\Support\Approvals\ApprovalNotifier;
use App\Support\Approvals\ApprovalOutcomeRegistry;
use App\Support\Approvals\ApprovalProcessStarter;
use App\Support\Approvals\ApprovalQuorumEvaluator;
use App\Support\Approvals\ApprovalStageEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DecideApprovalAction
{
    public function __construct(
        private readonly ApprovalStageEligibility $eligibility,
        private readonly ApprovalQuorumEvaluator $quorumEvaluator,
        private readonly ApprovalEventRecorder $eventRecorder,
        private readonly ApprovalProcessStarter $processStarter,
        private readonly ApprovalOutcomeRegistry $outcomes,
        private readonly ApprovalNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, ApprovalProcess $process, array $input): ApprovalProcess
    {
        $validated = Validator::make($input, [
            'decision' => [
                'required',
                'string',
                Rule::in([ApprovalEventType::Approved->value, ApprovalEventType::Rejected->value]),
            ],
            'reason' => ['required_if:decision,'.ApprovalEventType::Rejected->value, 'nullable', 'string', 'max:2000'],
            'onBehalfOfId' => ['nullable', 'string', 'ulid'],
        ])->validate();

        $decision = ApprovalEventType::from((string) $validated['decision']);
        $reason = isset($validated['reason']) ? (string) $validated['reason'] : null;
        $requestedOnBehalfOfId = isset($validated['onBehalfOfId']) ? (string) $validated['onBehalfOfId'] : null;

        try {
            return DB::transaction(function () use ($actor, $process, $decision, $reason, $requestedOnBehalfOfId): ApprovalProcess {
                $locked = ApprovalProcess::query()->whereKey($process->getKey())->lockForUpdate()->firstOrFail();

                $this->assertOpen($locked);

                $stage = $locked->currentStage();

                if (!$stage instanceof ApprovalProcessStage) {
                    throw ValidationException::withMessages([
                        'decision' => __('i18n.backend.actions.approvals.decide_approval_action.this_approval_process_has_no_open_stage_on_which'),
                    ]);
                }

                Gate::forUser($actor)->authorize('decide', [$locked, $requestedOnBehalfOfId]);

                $actorId = (string) $actor->getKey();

                $onBehalfOfId = $requestedOnBehalfOfId
                    ?? $this->eligibility->escalationDelegatorFor($actorId, $stage);

                $event = $this->eventRecorder->record($locked, $decision, [
                    'stage' => $stage,
                    'actor_id' => $actorId,
                    'on_behalf_of_id' => $onBehalfOfId,
                    'reason' => $reason,
                ]);

                $this->outcomes->for($locked->anchor_type)->recordDecision($locked, $stage, $event);

                return $decision === ApprovalEventType::Rejected
                    ? $this->reject($locked, $stage, $reason, $event)
                    : $this->approve($locked, $stage, $event);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([
                'decision' => __('i18n.backend.actions.approvals.decide_approval_action.you_have_already_decided_on_this_stage'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertOpen(ApprovalProcess $process): void
    {
        if ($process->status === ApprovalProcessStatus::Pending) {
            return;
        }

        throw ValidationException::withMessages([
            'decision' => __('i18n.backend.actions.approvals.decide_approval_action.this_approval_process_has_already_ended'),
        ]);
    }

    /**
     * @throws Throwable
     */
    private function reject(
        ApprovalProcess $process,
        ApprovalProcessStage $stage,
        ?string $reason,
        ApprovalEvent $event,
    ): ApprovalProcess {
        $stage->fill([
            'status' => ApprovalStageStatus::Rejected,
            'decided_at' => now(),
        ])->save();

        $process->fill([
            'status' => ApprovalProcessStatus::Rejected,
            'current_stage_position' => null,
            'finished_at' => now(),
        ])->save();

        $this->eventRecorder->record($process, ApprovalEventType::Completed, [
            'stage' => $stage,
            'reason' => $reason,
        ]);

        $this->outcomes->for($process->anchor_type)->applyRejected($process, $reason);

        $this->notifier->notifyDecision($event);

        return $process;
    }

    /**
     * @throws Throwable
     */
    private function approve(ApprovalProcess $process, ApprovalProcessStage $stage, ApprovalEvent $event): ApprovalProcess
    {
        if (!$this->quorumEvaluator->isSatisfied($stage)) {
            return $process;
        }

        $stage->fill([
            'status' => ApprovalStageStatus::Approved,
            'decided_at' => now(),
        ])->save();

        $nextDefinitionStage = ApprovalDefinitionStage::query()
            ->where('approval_definition_id', $process->approval_definition_id)
            ->where('position', '>', $stage->position)
            ->orderBy('position')
            ->first();

        if ($nextDefinitionStage instanceof ApprovalDefinitionStage) {
            $nextStage = $this->processStarter->startStage($process, $nextDefinitionStage, $process->attempt);

            $process->fill(['current_stage_position' => $nextDefinitionStage->position])->save();

            $this->eventRecorder->record($process, ApprovalEventType::StageStarted, [
                'stage' => $nextStage,
            ]);

            $this->notifier->notifyStageStarted($nextStage);

            return $process;
        }

        $process->fill([
            'status' => ApprovalProcessStatus::Approved,
            'current_stage_position' => null,
            'finished_at' => now(),
        ])->save();

        $this->eventRecorder->record($process, ApprovalEventType::Completed, [
            'stage' => $stage,
        ]);

        $this->outcomes->for($process->anchor_type)->applyApproved($process);

        $this->notifier->notifyDecision($event);

        return $process;
    }
}
