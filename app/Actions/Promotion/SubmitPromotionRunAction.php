<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\Contracts\Promotion\PromotionDispatcherInterface;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\Approvals\RecordBoundCandidateCircleException;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnresolvedDependenciesException;
use App\Models\ApprovalDefinition;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Approvals\ApprovalProcessStarter;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Promotion\PromotionPreviewBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitPromotionRunAction
{
    public function __construct(
        private readonly PromotionPreviewBuilder $previewBuilder,
        private readonly ApprovalProcessStarter $approvalStarter,
        private readonly AdminArtifactAuditor $auditor,
        private readonly PromotionDispatcherInterface $dispatcher,
    ) {}

    /**
     * @throws ValidationException
     * @throws UndecidedConflictsException
     * @throws UnresolvedDependenciesException
     * @throws RecordBoundCandidateCircleException
     * @throws Throwable
     */
    public function execute(User $actingUser, PromotionRun $run): PromotionRun
    {
        $this->previewBuilder->assertDraft($run);

        $comparison = $this->previewBuilder->compare($run);

        return DB::transaction(function () use ($actingUser, $run, $comparison): PromotionRun {
            $locked = PromotionRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            $blocker = $this->previewBuilder->submissionBlocker($locked, $comparison);

            if ($blocker !== null) {
                throw $blocker;
            }

            $definition = ApprovalDefinition::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('anchor_type', $locked->getMorphClass())
                ->whereNull('anchor_id')
                ->where('is_active', true)
                ->first();

            if (!$definition instanceof ApprovalDefinition) {
                return $this->approveWithoutProcess($actingUser, $locked);
            }

            $process = $this->approvalStarter->startForAnchor($locked, $definition, $locked->tenant_id, (string) $actingUser->getKey());

            $locked->fill([
                'status' => PromotionRunStatus::AwaitingApproval,
                'triggered_by_id' => (string) $actingUser->getKey(),
            ])->save();

            $this->auditor->recordEvent($locked, 'operation.promotion_submitted', [
                'selection' => $locked->selection,
                'conflictDecisions' => $locked->conflict_decisions,
                'approvalProcessId' => (string) $process->getKey(),
            ], $locked->tenant_id);

            return $locked;
        });
    }

    /**
     * @throws Throwable
     */
    private function approveWithoutProcess(User $actingUser, PromotionRun $locked): PromotionRun
    {
        $locked->fill([
            'status' => PromotionRunStatus::Approved,
            'triggered_by_id' => (string) $actingUser->getKey(),
        ])->save();

        $this->auditor->recordEvent($locked, 'operation.promotion_submitted', [
            'selection' => $locked->selection,
            'conflictDecisions' => $locked->conflict_decisions,
            'approvalProcessId' => null,
        ], $locked->tenant_id);

        DB::afterCommit(function () use ($locked): void {
            $this->dispatcher->start($locked);
        });

        return $locked;
    }
}
