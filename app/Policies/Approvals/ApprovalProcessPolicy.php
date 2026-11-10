<?php

declare(strict_types=1);

namespace App\Policies\Approvals;

use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\User;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Approvals\ApprovalSubjectSource;
use Illuminate\Database\Eloquent\Builder;

class ApprovalProcessPolicy
{
    public function __construct(
        private readonly ApprovalStageEligibility $eligibility,
        private readonly ApprovalSubjectSource $subjects,
    ) {}

    public function view(User $user, ApprovalProcess $process): bool
    {
        if ($process->tenant_id !== $user->tenant_id) {
            return false;
        }

        return $user->hasPermission('approvals.view') || $this->isParticipant($user, $process);
    }

    public function decide(User $user, ApprovalProcess $process, ?string $onBehalfOfId = null): bool
    {
        if ($process->tenant_id !== $user->tenant_id) {
            return false;
        }

        $stage = $this->subjects->currentStage($process);

        if (!$stage instanceof ApprovalProcessStage) {
            return false;
        }

        return $this->eligibility->mayDecide((string) $user->getKey(), $stage, $onBehalfOfId);
    }

    public function cancel(User $user, ApprovalProcess $process): bool
    {
        if ($process->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($process->status !== ApprovalProcessStatus::Pending) {
            return false;
        }

        return $user->hasPermission('approvals.configure')
            || $process->triggered_by_id === (string) $user->getKey();
    }

    private function isParticipant(User $user, ApprovalProcess $process): bool
    {
        $userId = (string) $user->getKey();

        if ($process->triggered_by_id === $userId) {
            return true;
        }

        $stage = $this->subjects->currentStage($process);

        if ($stage instanceof ApprovalProcessStage
            && in_array($userId, $this->eligibility->eligibleUserIds($stage), true)) {
            return true;
        }

        return $process->events()
            ->where(static function (Builder $query) use ($userId): void {
                $query->where('actor_id', $userId)->orWhere('on_behalf_of_id', $userId);
            })
            ->exists();
    }
}
