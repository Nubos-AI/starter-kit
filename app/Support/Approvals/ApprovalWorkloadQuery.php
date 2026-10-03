<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\DTOs\Approvals\ApprovalWorkloadItem;
use App\Enums\Approvals\ApprovalExclusion;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\User;
use App\Support\Governance\AbsenceDelegationResolver;
use App\Support\Users\UserOptionPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ApprovalWorkloadQuery
{
    public function __construct(
        private readonly ApprovalStageEligibility $eligibility,
        private readonly ApprovalExclusionResolver $exclusionResolver,
        private readonly AbsenceDelegationResolver $absenceDelegationResolver,
        private readonly ApprovalRecordContext $recordContext,
        private readonly UserOptionPresenter $userOptionPresenter,
    ) {}

    /**
     * @return Collection<int, ApprovalWorkloadItem>
     */
    public function openFor(User $user): Collection
    {
        $actorId = (string) $user->getKey();
        $delegators = $this->delegatorsOf($user);

        $items = [];

        foreach ($this->pendingProcessesOf($user) as $process) {
            $stage = $this->openStageOf($process);

            if (!$stage instanceof ApprovalProcessStage) {
                continue;
            }

            if ($this->eligibility->mayDecide($actorId, $stage, null)) {
                $items[] = new ApprovalWorkloadItem($process, $stage, null, null, true, null, []);

                continue;
            }

            $delegatorId = $this->decidableDelegatorIds($actorId, $stage, $delegators)[0] ?? null;

            if ($delegatorId === null) {
                continue;
            }

            $items[] = new ApprovalWorkloadItem(
                $process,
                $stage,
                $delegatorId,
                $this->userOptionPresenter->label($delegators[$delegatorId]),
                true,
                null,
                [],
            );
        }

        return new Collection($items);
    }

    public function contextFor(User $user, ApprovalProcess $process): ApprovalWorkloadItem
    {
        $actorId = (string) $user->getKey();
        $stage = $this->openStageOf($process);

        if (!$stage instanceof ApprovalProcessStage) {
            return new ApprovalWorkloadItem(
                $process,
                null,
                null,
                null,
                false,
                __('i18n.backend.support.approvals.approval_workload_query.this_process_currently_has_no_open_stage_to_decide'),
                [],
            );
        }

        $delegators = $this->delegatorsOf($user);
        $isDirectApprover = $this->eligibility->mayDecide($actorId, $stage, null);

        $decidableDelegatorIds = $isDirectApprover
            ? []
            : $this->decidableDelegatorIds($actorId, $stage, $delegators);

        $canDecide = $isDirectApprover || $decidableDelegatorIds !== [];
        $onBehalfOfId = $decidableDelegatorIds[0] ?? null;

        return new ApprovalWorkloadItem(
            $process,
            $stage,
            $onBehalfOfId,
            $onBehalfOfId === null ? null : $this->userOptionPresenter->label($delegators[$onBehalfOfId]),
            $canDecide,
            $canDecide ? null : $this->decisionReason($actorId, $process),
            $this->userOptionPresenter->presentMany($delegators->only($decidableDelegatorIds)->values()),
        );
    }

    /**
     * @return EloquentCollection<int, ApprovalProcess>
     */
    private function pendingProcessesOf(User $user): EloquentCollection
    {
        return ApprovalProcess::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', ApprovalProcessStatus::Pending)
            ->with([
                'stages',
                'anchor',
                'record' => fn ($query) => $query->withTrashed()->with('objectType'),
                ...config('modules.approvals.eager_loads', []),
            ])
            ->orderBy('started_at')
            ->get();
    }

    private function openStageOf(ApprovalProcess $process): ?ApprovalProcessStage
    {
        if ($process->current_stage_position === null) {
            return null;
        }

        $process->loadMissing('stages');

        return $process->stages->first(
            static fn (ApprovalProcessStage $stage): bool => $stage->attempt === $process->attempt
                && $stage->position === $process->current_stage_position,
        );
    }

    /**
     * @return EloquentCollection<string, User>
     */
    private function delegatorsOf(User $user): EloquentCollection
    {
        $delegatorIds = $this->absenceDelegationResolver->delegatorsFor(
            (string) $user->getKey(),
            CarbonImmutable::now(),
        );

        if ($delegatorIds === []) {
            /** @var EloquentCollection<string, User> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        /** @var EloquentCollection<string, User> $delegators */
        $delegators = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($delegatorIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->keyBy(static fn (User $delegator): string => (string) $delegator->getKey());

        return $delegators;
    }

    /**
     * @param  EloquentCollection<string, User>  $delegators
     * @return list<string>
     */
    private function decidableDelegatorIds(
        string $actorId,
        ApprovalProcessStage $stage,
        EloquentCollection $delegators,
    ): array {
        return array_values(array_filter(
            array_map(static fn (mixed $key): string => $key, $delegators->keys()->all()),
            fn (string $delegatorId): bool => $this->eligibility->mayDecide($actorId, $stage, $delegatorId),
        ));
    }

    private function decisionReason(string $actorId, ApprovalProcess $process): string
    {
        if (!$this->eligibility->holdsDecisionPermission($actorId, $process)) {
            return __('i18n.backend.support.approvals.approval_workload_query.you_do_not_have_permission_to_decide_on_this');
        }

        return $this->exclusionReason($actorId, $process)
            ?? __('i18n.backend.support.approvals.approval_workload_query.you_are_not_part_of_the_approver_pool_at');
    }

    private function exclusionReason(string $actorId, ApprovalProcess $process): ?string
    {
        $definition = $process->definition()->first();

        if (!$definition instanceof ApprovalDefinition) {
            return null;
        }

        $record = $process->record_id === null ? null : $process->record()->withTrashed()->first();

        $reasons = $this->exclusionResolver->reasonsFor(
            $actorId,
            $record,
            $this->eligibility->exclusionsFor($definition, $process->anchor_type),
            $process->triggered_by_id,
            $this->recordContext->fields($process),
        );

        if ($reasons === []) {
            return null;
        }

        $labels = array_map(static fn (ApprovalExclusion $reason): string => $reason->label(), $reasons);

        return __('i18n.backend.support.approvals.approval_workload_query.you_are_excluded_from_this_stage').implode(', ', $labels).'.';
    }
}
