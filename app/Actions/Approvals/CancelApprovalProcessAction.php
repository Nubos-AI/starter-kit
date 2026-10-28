<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Approvals\ApprovalStageStatus;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\User;
use App\Support\Approvals\ApprovalEventRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CancelApprovalProcessAction
{
    private string $defaultReason = 'i18n.backend.actions.approvals.cancel_approval_process_action.cancelled_by_an_authorised_person';

    public function __construct(
        private readonly ApprovalEventRecorder $eventRecorder,
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
            'reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $reason = isset($validated['reason']) && (string) $validated['reason'] !== ''
            ? (string) $validated['reason']
            : __($this->defaultReason);

        return DB::transaction(function () use ($actor, $process, $reason): ApprovalProcess {
            $locked = ApprovalProcess::query()->whereKey($process->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('cancel', $locked);

            return $this->cancelLocked($locked, $reason, (string) $actor->getKey());
        });
    }

    /**
     * @throws Throwable
     */
    private function cancelLocked(ApprovalProcess $locked, string $reason, string $actorId): ApprovalProcess
    {
        $stage = $locked->currentStage();

        if ($stage instanceof ApprovalProcessStage) {
            $stage->fill(['status' => ApprovalStageStatus::Superseded])->save();
        }

        $locked->fill([
            'status' => ApprovalProcessStatus::Cancelled,
            'cancellation_reason' => $reason,
            'current_stage_position' => null,
            'finished_at' => now(),
        ])->save();

        $this->eventRecorder->record($locked, ApprovalEventType::Cancelled, [
            'stage' => $stage,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);

        return $locked;
    }
}
