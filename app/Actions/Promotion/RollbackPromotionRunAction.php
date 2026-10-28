<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\Actions\Engine\RollbackDefinitionAction;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\Promotion\PromotionNotRollbackableException;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Promotion\BundleDiffer;
use App\Support\Promotion\PromotionBaselineStore;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class RollbackPromotionRunAction
{
    public function __construct(
        private readonly RollbackDefinitionAction $rollbackDefinition,
        private readonly ActingUserContext $actingUserContext,
        private readonly ConfigBundleSerializer $serializer,
        private readonly BundleDiffer $differ,
        private readonly PromotionBaselineStore $baselines,
        private readonly AdminArtifactAuditor $auditor,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws PromotionNotRollbackableException
     * @throws MissingActingUserException
     * @throws TenantUnderMaintenanceException
     * @throws Throwable
     */
    public function execute(User $actingUser, PromotionRun $run): PromotionRun
    {
        $tenantId = $run->tenant_id;

        if ((string) $actingUser->tenant_id !== $tenantId) {
            throw new AuthorizationException(__('i18n.backend.actions.promotion.rollback_promotion_run_action.the_acting_user_does_not_belong_to_this_promotion'));
        }

        $this->maintenanceLocks->assertWritable($tenantId, 'promotion_rollback', [
            'promotion_run_id' => (string) $run->getKey(),
        ]);

        /** @var PromotionRun|null $rolledBack */
        $rolledBack = null;

        DB::transaction(function () use ($actingUser, $run, $tenantId, &$rolledBack): void {
            $this->actingUserContext->run(
                $tenantId,
                (string) $actingUser->getKey(),
                function (User $boundUser) use ($run, $tenantId, &$rolledBack): void {
                    $rolledBack = $this->rollBack($boundUser, $run, $tenantId);
                },
            );
        });

        /** @var PromotionRun $rolledBack */
        return $rolledBack;
    }

    /**
     * @throws AuthorizationException
     * @throws PromotionNotRollbackableException
     * @throws MissingActingUserException
     * @throws Throwable
     */
    private function rollBack(User $boundUser, PromotionRun $run, string $tenantId): PromotionRun
    {
        if (!$boundUser->hasPermission('promotions.execute')) {
            throw new AuthorizationException(__('i18n.backend.actions.promotion.rollback_promotion_run_action.only_users_with_promotion_permission_may_revert_a_promotion'));
        }

        $locked = PromotionRun::query()
            ->whereKey($run->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $this->ensureRollbackable($locked);

        $snapshotGroupId = (string) $locked->snapshot_group_id;

        $before = $this->serializer->serialize($tenantId);
        $results = $this->rollbackDefinition->rollbackGroup($boundUser, $snapshotGroupId, $this->selectedKindsOf($locked));
        $after = $this->serializer->serialize($tenantId);

        $forgotten = $this->forgetMovedBaselines($locked->counterpart_key, $before, $after);
        $scalarResults = $this->scalarResults($results);
        $actionCounts = array_count_values(array_column($scalarResults, 'action'));

        $locked->fill([
            'status' => PromotionRunStatus::RolledBack,
            'finished_at' => now(),
            'report' => [
                ...($locked->report ?? []),
                'rollback' => [
                    'snapshot_group_id' => $snapshotGroupId,
                    'rolled_back_by_id' => (string) $boundUser->getKey(),
                    'results' => $scalarResults,
                    'forgotten_baselines' => $forgotten,
                ],
            ],
        ])->save();

        $this->auditor->recordEvent($locked, 'operation.promotion_rolled_back', [
            'snapshot_group_id' => $snapshotGroupId,
            'counterpart_key' => $locked->counterpart_key,
            'results' => $actionCounts,
            'forgotten_baselines' => $forgotten,
        ], $tenantId);

        return $locked;
    }

    /**
     * @throws PromotionNotRollbackableException
     */
    private function ensureRollbackable(PromotionRun $run): void
    {
        if ($run->status === PromotionRunStatus::RolledBack) {
            throw PromotionNotRollbackableException::alreadyRolledBack();
        }

        if ($run->status !== PromotionRunStatus::Completed && $run->status !== PromotionRunStatus::Failed) {
            throw PromotionNotRollbackableException::notExecuted();
        }

        if ($run->snapshot_group_id === null) {
            throw PromotionNotRollbackableException::withoutSnapshotGroup();
        }
    }

    /**
     * @return list<ArtifactKind>
     */
    private function selectedKindsOf(PromotionRun $run): array
    {
        $kinds = [];

        foreach ($run->promotion_selection->pairs as $pair) {
            $kinds[$pair['kind']->value] = $pair['kind'];
        }

        return array_values($kinds);
    }

    /**
     * @return list<string>
     *
     * @throws Throwable
     */
    private function forgetMovedBaselines(string $counterpartKey, ConfigBundle $before, ConfigBundle $after): array
    {
        $forgotten = [];

        foreach ($this->differ->diff($after, $before, $counterpartKey)->diffs as $entry) {
            if ($entry->state === DiffState::Unchanged || $entry->baselineHash === null) {
                continue;
            }

            $identifier = $entry->kind->identifierFor($entry->key);

            $this->baselines->forgetOne($counterpartKey, $identifier);
            $forgotten[] = $identifier;
        }

        return $forgotten;
    }

    /**
     * @param  list<ArtifactWriteResult>  $results
     * @return list<array{kind: string, key: string, action: string, model_id: string|null, notes: list<string>}>
     */
    private function scalarResults(array $results): array
    {
        return array_map(
            static fn (ArtifactWriteResult $result): array => [
                'kind' => $result->kind->value,
                'key' => $result->key,
                'action' => $result->action->value,
                'model_id' => $result->modelId,
                'notes' => $result->notes,
            ],
            $results,
        );
    }
}
