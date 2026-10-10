<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\Actions\Engine\SnapshotDefinitionAction;
use App\DTOs\ConfigBundle\ApplyReport;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\AmbiguousArtifactKeyException;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnresolvedDependenciesException;
use App\Exceptions\ConfigBundle\UnresolvedPlaceholderException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\Promotion\CyclicArtifactDependencyException;
use App\Exceptions\Promotion\PromotionNotExecutableException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Models\Role;
use App\Models\User;
use App\Scopes\ObjectTypeTenantScope;
use App\Scopes\TenantScope;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\ConfigBundle\ConfigBundleApplier;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Promotion\ArtifactDependencyResolver;
use App\Support\Promotion\BundleDiffer;
use App\Support\Promotion\PromotionBaselineStore;
use App\Support\Promotion\PromotionSourceResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ExecutePromotionRunAction
{
    private int $roleKeyComponents = 2;

    /**
     * @var list<class-string<Throwable>>
     */
    private array $userFacingExceptions = [
        PromotionNotExecutableException::class,
        PromotionSourceUnavailableException::class,
        UnresolvedPlaceholderException::class,
        UndecidedConflictsException::class,
        UnresolvedDependenciesException::class,
        CyclicArtifactDependencyException::class,
        MalformedBundleException::class,
        UnsupportedBundleSchemaVersionException::class,
        AmbiguousArtifactKeyException::class,
        TenantUnderMaintenanceException::class,
    ];

    private string $genericFailureMessage = 'i18n.backend.actions.promotion.execute_promotion_run_action.the_promotion_failed_due_to_an_unexpected_technical_error';

    private string $nothingWrittenMessage = 'i18n.backend.actions.promotion.execute_promotion_run_action.none_of_the_selected_artifacts_was_written_to_live';

    public function __construct(
        private readonly ActingUserContext $actingUserContext,
        private readonly PromotionSourceResolver $sourceResolver,
        private readonly ConfigBundleSerializer $serializer,
        private readonly BundleDiffer $differ,
        private readonly ArtifactDependencyResolver $dependencyResolver,
        private readonly TargetKeyResolver $targetKeys,
        private readonly SnapshotDefinitionAction $snapshotAction,
        private readonly ConfigBundleApplier $applier,
        private readonly PromotionBaselineStore $baselines,
        private readonly AdminArtifactAuditor $auditor,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws PromotionNotExecutableException
     * @throws TenantUnderMaintenanceException
     * @throws Throwable
     */
    public function execute(PromotionRun $run): PromotionRun
    {
        $runId = (string) $run->getKey();
        $tenantId = $run->tenant_id;

        $this->claim($runId, $tenantId);

        /** @var PromotionRun|null $executed */
        $executed = null;

        try {
            $this->maintenanceLocks->assertWritable($tenantId, 'promotion', [
                'promotion_run_id' => $runId,
            ]);

            $this->actingUserContext->run(
                $tenantId,
                $run->triggered_by_id,
                function (User $actingUser) use ($runId, &$executed): void {
                    $executed = $this->executeClaimed(
                        PromotionRun::query()->whereKey($runId)->firstOrFail(),
                        $actingUser,
                    );
                },
            );
        } catch (Throwable $exception) {
            $this->markFailed($runId, $tenantId, $exception);

            throw $exception;
        }

        /** @var PromotionRun $executed */
        return $executed;
    }

    /**
     * @throws PromotionNotExecutableException
     */
    private function claim(string $runId, string $tenantId): void
    {
        $claimed = PromotionRun::withoutTenantScope()
            ->whereKey($runId)
            ->where('tenant_id', $tenantId)
            ->where('status', PromotionRunStatus::Approved)
            ->update([
                'status' => PromotionRunStatus::Applying,
                'started_at' => now(),
            ]);

        if ($claimed === 0) {
            throw PromotionNotExecutableException::notApproved();
        }
    }

    /**
     * @throws Throwable
     */
    private function executeClaimed(PromotionRun $run, User $actingUser): PromotionRun
    {
        if ((string) $actingUser->tenant_id !== $run->tenant_id) {
            throw PromotionNotExecutableException::actingUserOutsideTenant();
        }

        if (!$actingUser->hasPermission('promotions.execute')) {
            throw PromotionNotExecutableException::triggerWithoutAuthority();
        }

        $this->sourceResolver->assertResolvable($run);

        $source = $this->sourceResolver->resolve($run);
        $target = $this->serializer->serialize($run->tenant_id);
        $diff = $this->differ->diff($source, $target, $run->counterpart_key);
        $selection = $this->selectionOf($run);
        $decisions = $this->decisionsOf($run);

        $this->assertConflictsUnchanged($diff, $selection, $decisions);

        $snapshotGroupId = (string) Str::ulid();

        $this->snapshotAction->executeForArtifacts(
            $this->snapshotArtifactsOf($selection, $source, $target, $diff),
            $snapshotGroupId,
            "Automatic snapshot before promotion {$run->getKey()}",
        );

        $run->fill(['snapshot_group_id' => $snapshotGroupId])->save();

        $report = $this->applier->apply($actingUser, $source, $diff, $selection, $decisions, $run->overwrite_selection);

        $this->complete($run, $diff, $selection, $decisions, $report);

        return $run;
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     *
     * @throws Throwable
     */
    private function complete(
        PromotionRun $run,
        BundleDiff $diff,
        PromotionSelection $selection,
        array $decisions,
        ApplyReport $report,
    ): void {
        $writtenCount = count(array_filter(
            $report->results,
            static fn (ArtifactWriteResult $result): bool => $result->action !== ArtifactWriteAction::Skipped,
        ));
        $skippedCount = count($report->results) - $writtenCount;
        $wroteNothing = $writtenCount === 0 && $skippedCount > 0;

        DB::transaction(function () use ($run, $diff, $selection, $decisions, $report, $writtenCount, $skippedCount, $wroteNothing): void {
            $this->advanceBaselines($run->counterpart_key, $diff, $selection, $decisions, $report);

            $this->auditor->recordEvent($run, 'operation.promotion_applied', [
                'selection' => $run->selection,
                'conflictDecisions' => $run->conflict_decisions,
                'snapshotGroupId' => $run->snapshot_group_id,
                'writtenCount' => $writtenCount,
            ], $run->tenant_id);

            $run->fill([
                'status' => $wroteNothing ? PromotionRunStatus::Failed : PromotionRunStatus::Completed,
                'finished_at' => now(),
                'report' => [
                    'error' => $wroteNothing ? __($this->nothingWrittenMessage) : null,
                    'results' => array_map(
                        static fn (ArtifactWriteResult $result): array => [
                            'kind' => $result->kind->value,
                            'key' => $result->key,
                            'action' => $result->action->value,
                            'notes' => $result->notes,
                        ],
                        $report->results,
                    ),
                    'pulled_in' => array_map(
                        static fn (array $pull): array => [
                            'kind' => $pull['kind']->value,
                            'key' => $pull['key'],
                            'optional' => $pull['optional'],
                        ],
                        $report->pulledIn,
                    ),
                    'notes' => $report->notes,
                    'written_count' => $writtenCount,
                    'skipped_count' => $skippedCount,
                    'snapshot_group_id' => $run->snapshot_group_id,
                ],
            ])->save();
        });
    }

    private function markFailed(string $runId, string $tenantId, Throwable $exception): void
    {
        Log::error('Promotion run failed.', [
            'tenant_id' => $tenantId,
            'promotion_run_id' => $runId,
            'exception_class' => $exception::class,
            'exception' => $exception->getMessage(),
        ]);

        $run = PromotionRun::withoutTenantScope()
            ->whereKey($runId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $run->fill([
            'status' => PromotionRunStatus::Failed,
            'finished_at' => now(),
            'report' => ['error' => $this->reportableMessageOf($exception)],
        ])->save();

        $this->recordFailureAudit($run, $tenantId, $exception);
    }

    private function recordFailureAudit(PromotionRun $run, string $tenantId, Throwable $exception): void
    {
        try {
            $this->auditor->recordEvent($run, 'operation.promotion_failed', [
                'error' => $this->reportableMessageOf($exception),
                'exceptionClass' => $exception::class,
                'triggeredById' => $run->triggered_by_id,
                'snapshotGroupId' => $run->snapshot_group_id,
            ], $tenantId);
        } catch (Throwable $auditFailure) {
            Log::error('Auditing a failed promotion run failed.', [
                'tenant_id' => $tenantId,
                'promotion_run_id' => (string) $run->getKey(),
                'exception_class' => $auditFailure::class,
                'exception' => $auditFailure->getMessage(),
            ]);
        }
    }

    private function reportableMessageOf(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->implode(' ');
        }

        foreach ($this->userFacingExceptions as $userFacingException) {
            if ($exception instanceof $userFacingException) {
                return $exception->getMessage();
            }
        }

        return __($this->genericFailureMessage);
    }

    private function selectionOf(PromotionRun $run): PromotionSelection
    {
        $pairs = [];

        foreach ($run->selection as $pair) {
            $kind = data_get($pair, 'kind');
            $key = data_get($pair, 'key');

            if (!is_string($kind) || !is_string($key) || $key === '') {
                throw new InvalidArgumentException(__('i18n.backend.actions.promotion.execute_promotion_run_action.every_entry_of_a_promotion_selection_needs_an_artifact'));
            }

            $pairs[] = ['kind' => ArtifactKind::from($kind), 'key' => $key];
        }

        return new PromotionSelection($pairs);
    }

    /**
     * @return list<ConflictDecision>
     */
    private function decisionsOf(PromotionRun $run): array
    {
        $decisions = [];

        foreach ($run->conflict_decisions as $decision) {
            if (!is_array($decision)) {
                throw new InvalidArgumentException(__('i18n.backend.actions.promotion.execute_promotion_run_action.every_conflict_decision_of_a_promotion_run_must_be'));
            }

            $decisions[] = ConflictDecision::fromArray($decision);
        }

        return $decisions;
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     *
     * @throws PromotionNotExecutableException
     */
    private function assertConflictsUnchanged(BundleDiff $diff, PromotionSelection $selection, array $decisions): void
    {
        $undecided = array_map(
            static fn (ArtifactDiff $conflict): string => $conflict->kind->identifierFor($conflict->key),
            array_values(array_filter(
                $diff->conflicts(),
                static fn (ArtifactDiff $conflict): bool => $selection->contains($conflict->kind, $conflict->key)
                    && !$conflict->isDecidable($decisions),
            )),
        );

        $entries = $this->entriesOf($diff);
        $outdated = [];

        foreach ($decisions as $decision) {
            $identifier = $decision->kind->identifierFor($decision->key);

            if (($entries[$identifier] ?? null)?->state !== DiffState::Conflicted) {
                $outdated[] = $identifier;
            }
        }

        if ($undecided === [] && $outdated === []) {
            return;
        }

        throw PromotionNotExecutableException::conflictsChanged($undecided, $outdated);
    }

    /**
     * @return list<array{kind: ArtifactKind, model: Model}>
     */
    private function snapshotArtifactsOf(PromotionSelection $selection, ConfigBundle $source, ConfigBundle $target, BundleDiff $diff): array
    {
        $touched = [];

        foreach ($this->dependencyResolver->resolve($selection, $source, $diff)->selection->pairs as $pair) {
            $kind = $pair['kind'] === ArtifactKind::FieldDefinitions ? ArtifactKind::ObjectTypes : $pair['kind'];
            $touched[$kind->value] = true;
        }

        $artifacts = [];

        foreach ($target->artifacts as $artifact) {
            if (isset($touched[$artifact->kind->value])) {
                $artifacts[] = [
                    'kind' => $artifact->kind,
                    'model' => $this->targetModelOf($artifact->kind, $artifact->key),
                ];
            }
        }

        return $artifacts;
    }

    private function targetModelOf(ArtifactKind $kind, string $key): Model
    {
        $model = $kind === ArtifactKind::RolePermissions
            ? $this->rolePermissionOf($key)
            : $this->rowOf($kind, $this->targetKeys->idFor($kind, $key));

        if (!$model instanceof Model) {
            throw new InvalidArgumentException("The target artifact [{$kind->identifierFor($key)}] resolves to no row, so the snapshot before the promotion would be incomplete.");
        }

        return $model;
    }

    private function rowOf(ArtifactKind $kind, ?string $id): ?Model
    {
        $modelClass = $this->modelClassOf($kind);

        if ($id === null || $modelClass === null) {
            return null;
        }

        return $modelClass::query()
            ->withoutGlobalScopes([TenantScope::class, ObjectTypeTenantScope::class])
            ->whereKey($id)
            ->first();
    }

    private function rolePermissionOf(string $key): ?Model
    {
        $components = explode(':', $key);

        $roleId = $this->targetKeys->idFor(
            ArtifactKind::Roles,
            implode(':', array_slice($components, 0, $this->roleKeyComponents)),
        );

        $permissionId = $this->targetKeys->idFor(
            'permissions',
            implode(':', array_slice($components, $this->roleKeyComponents)),
        );

        if ($roleId === null || $permissionId === null) {
            return null;
        }

        $pivot = Role::query()
            ->whereKey($roleId)
            ->first()
            ?->permissions()
            ->wherePivot('permission_id', $permissionId)
            ->first()
            ?->getRelation('pivot');

        return $pivot instanceof Model ? $pivot : null;
    }

    /**
     * @return class-string<Model>|null
     */
    private function modelClassOf(ArtifactKind $kind): ?string
    {
        $configured = config('engine.tenant_artifacts');

        foreach (is_array($configured) ? $configured : [] as $entry) {
            $model = data_get($entry, 'kind') === $kind->value ? data_get($entry, 'model') : null;

            if (is_string($model) && is_a($model, Model::class, true)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     */
    private function advanceBaselines(
        string $counterpartKey,
        BundleDiff $diff,
        PromotionSelection $selection,
        array $decisions,
        ApplyReport $report,
    ): void {
        $entries = $this->entriesOf($diff);
        $skipped = [];

        foreach ($report->results as $result) {
            if ($result->action === ArtifactWriteAction::Skipped) {
                $skipped[$result->kind->identifierFor($result->key)] = true;
            }
        }

        $decided = $selection;

        foreach ($report->pulledIn as $pull) {
            $decided = $pull['optional'] ? $decided : $decided->withAdded($pull['kind'], $pull['key']);
        }

        foreach ($decided->pairs as $pair) {
            $identifier = $pair['kind']->identifierFor($pair['key']);
            $entry = $entries[$identifier] ?? null;

            if ($entry === null || isset($skipped[$identifier])) {
                continue;
            }

            $this->advanceBaseline($counterpartKey, $identifier, $entry, $decisions);
        }
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     */
    private function advanceBaseline(string $counterpartKey, string $identifier, ArtifactDiff $entry, array $decisions): void
    {
        if ($entry->state === DiffState::Removed) {
            $this->baselines->forgetOne($counterpartKey, $identifier);

            return;
        }

        $keepsTarget = $entry->state === DiffState::Conflicted
            && $this->decisionFor($decisions, $entry)?->resolution === ConflictResolution::KeepTarget;

        $hash = $keepsTarget ? $entry->targetHash : $entry->sourceHash;

        if ($hash !== null) {
            $this->baselines->rememberOne($counterpartKey, $identifier, $hash);
        }
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     */
    private function decisionFor(array $decisions, ArtifactDiff $entry): ?ConflictDecision
    {
        foreach ($decisions as $decision) {
            if ($decision->kind === $entry->kind && $decision->key === $entry->key) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * @return array<string, ArtifactDiff>
     */
    private function entriesOf(BundleDiff $diff): array
    {
        $entries = [];

        foreach ($diff->diffs as $entry) {
            $entries[$entry->kind->identifierFor($entry->key)] = $entry;
        }

        return $entries;
    }
}
