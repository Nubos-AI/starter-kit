<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Database\Eloquent\Collection;

class ApprovalSubjectSource
{
    public function __construct(private readonly TenantBinder $tenantBinder) {}

    public function process(ApprovalProcessStage|ApprovalEvent $subject): ApprovalProcess
    {
        return $subject->process()->firstOrFail();
    }

    public function processWithRecord(ApprovalProcessStage|ApprovalEvent $subject): ApprovalProcess
    {
        return $subject->process()
            ->with(['record' => static fn ($query) => $query->withTrashed()])
            ->firstOrFail();
    }

    public function currentStage(ApprovalProcess $process): ?ApprovalProcessStage
    {
        return $process->currentStage();
    }

    public function definition(ApprovalProcess $process): ApprovalDefinition
    {
        return $process->definition()->firstOrFail();
    }

    public function definitionStage(ApprovalProcessStage $stage): ApprovalDefinitionStage
    {
        return $stage->definitionStage()->firstOrFail();
    }

    public function record(ApprovalProcess $process): ?CustomRecord
    {
        return $process->record_id === null ? null : $process->record()->withTrashed()->firstOrFail();
    }

    public function recordIfStillThere(ApprovalProcess $process): ?CustomRecord
    {
        return $process->record_id === null ? null : $process->record()->withTrashed()->first();
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function permissionHolderIds(string $tenantId, array $userIds, string $permission): array
    {
        $holderIds = $this->tenantBinder->runIfKnown(
            $tenantId,
            static fn (): array => User::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($userIds)
                ->get()
                ->filter(static fn (User $user): bool => $user->hasPermission($permission))
                ->map(static fn (User $user): string => (string) $user->getKey())
                ->all(),
        );

        return is_array($holderIds) ? array_values($holderIds) : [];
    }

    public function approvedDecisionCount(ApprovalProcessStage $stage): int
    {
        return ApprovalEvent::query()
            ->where('approval_process_stage_id', $stage->getKey())
            ->where('type', ApprovalEventType::Approved)
            ->count();
    }

    /**
     * @return Collection<int, ApprovalProcess>
     */
    public function lockedPendingProcessesFor(CustomRecord $record): Collection
    {
        return ApprovalProcess::query()
            ->where('tenant_id', $record->tenant_id)
            ->where('record_id', $record->getKey())
            ->where('status', ApprovalProcessStatus::Pending)
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  list<string>  $recipientIds
     * @return Collection<int, User>
     */
    public function recipients(string $tenantId, array $recipientIds): Collection
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($recipientIds)
            ->orderBy('id')
            ->get();
    }

    public function displayName(string $tenantId, string $userId): ?string
    {
        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->first();

        if (!$user instanceof User) {
            return null;
        }

        return $user->name === '' ? null : $user->name;
    }
}
