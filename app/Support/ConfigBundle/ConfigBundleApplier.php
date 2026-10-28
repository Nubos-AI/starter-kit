<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\Contracts\ConfigBundle\ArtifactRefusalInterface;
use App\DTOs\ConfigBundle\ApplyReport;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\DependencyResolution;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnresolvedDependenciesException;
use App\Exceptions\ConfigBundle\UnresolvedPlaceholderException;
use App\Models\User;
use App\Support\Promotion\ArtifactDependencyResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConfigBundleApplier
{
    public function __construct(
        private readonly ActingUserContext $actingUserContext,
        private readonly ArtifactDependencyResolver $dependencyResolver,
        private readonly ArtifactWriteSequence $writeSequence,
        private readonly ArtifactWriterRegistry $writers,
    ) {}

    /**
     * @param  list<ConflictDecision>  $conflictDecisions
     *
     * @throws UnresolvedPlaceholderException
     * @throws UndecidedConflictsException
     * @throws UnresolvedDependenciesException
     */
    public function apply(
        User $actingUser,
        ConfigBundle $source,
        BundleDiff $diff,
        PromotionSelection $selection,
        array $conflictDecisions,
        ?PromotionSelection $overwrites = null,
    ): ApplyReport {
        $decided = $this->withoutKeptTargets($selection, $diff, $conflictDecisions);

        $this->refuseUnresolvedPlaceholders($this->planOf($source, $diff, $decided)['writes']);

        if ($diff->hasUndecidedConflicts($selection, $conflictDecisions)) {
            throw UndecidedConflictsException::forUndecided($this->undecidedOf($diff, $selection, $conflictDecisions));
        }

        $resolution = $this->dependencyResolver->resolve($decided, $source, $diff);

        if ($resolution->refusals !== []) {
            throw UnresolvedDependenciesException::forRefusals($resolution->refusals);
        }

        return new ApplyReport(
            results: $this->writeInTenant((string) $actingUser->tenant_id, $actingUser, $source, $diff, $resolution, $overwrites ?? new PromotionSelection([])),
            pulledIn: $resolution->pulledIn,
            notes: $this->notesOf($resolution),
        );
    }

    /**
     * @return list<ArtifactWriteResult>
     *
     * @throws AuthorizationException
     * @throws Throwable
     */
    private function writeInTenant(string $tenantId, User $actingUser, ConfigBundle $source, BundleDiff $diff, DependencyResolution $resolution, PromotionSelection $overwrites): array
    {
        /** @var list<ArtifactWriteResult> $results */
        $results = [];

        DB::transaction(function () use ($tenantId, $actingUser, $source, $diff, $resolution, $overwrites, &$results): void {
            $this->actingUserContext->run(
                $tenantId,
                (string) $actingUser->getKey(),
                function (User $boundUser) use ($source, $diff, $resolution, $overwrites, &$results): void {
                    $results = $this->writeRun($boundUser, $source, $diff, $resolution, $overwrites);
                },
            );
        });

        return $results;
    }

    /**
     * @return list<ArtifactWriteResult>
     */
    private function writeRun(User $actingUser, ConfigBundle $source, BundleDiff $diff, DependencyResolution $resolution, PromotionSelection $overwrites): array
    {
        $plan = $this->planOf($source, $diff, $resolution->selection);
        $results = $plan['skipped'];

        foreach ($plan['writes'] as $write) {
            $writer = $this->writers->for($write['kind']);

            if ($writer instanceof ArtifactRefusalInterface && $overwrites->contains($write['kind'], $write['artifact']->key)) {
                $writer->clearForOverwrite($write['kind'], $write['artifact']);
            }
        }

        foreach ($plan['removals'] as $removal) {
            $results[] = $this->writers->for($removal['kind'])->remove($actingUser, $removal['kind'], $removal['key']);
        }

        $results = [...$results, ...$this->writeSequence->apply($this->writers, $actingUser, $plan['writes'])];

        foreach ($this->writers->all() as $writer) {
            $results = [...$results, ...$writer->flush($actingUser)];
        }

        return $results;
    }

    /**
     * @return array{removals: list<array{kind: ArtifactKind, key: string, rank: int}>, writes: list<array{kind: ArtifactKind, artifact: BundleArtifact, state: DiffState, rank: int}>, skipped: list<ArtifactWriteResult>}
     */
    private function planOf(ConfigBundle $source, BundleDiff $diff, PromotionSelection $selection): array
    {
        $states = $this->statesOf($diff);
        $ranks = $this->ranks();

        $removals = [];
        $writes = [];
        $skipped = [];

        foreach ($selection->pairs as $pair) {
            $kind = $pair['kind'];
            $key = $pair['key'];
            $rank = $ranks[$kind->value] ?? count($ranks);
            $state = $states[$kind->identifierFor($key)] ?? DiffState::Added;

            if ($state === DiffState::Unchanged) {
                continue;
            }

            if ($state === DiffState::Removed) {
                $removals[] = ['kind' => $kind, 'key' => $key, 'rank' => $rank];

                continue;
            }

            $artifact = $source->find($kind, $key);

            if ($artifact === null) {
                $skipped[] = new ArtifactWriteResult(
                    kind: $kind,
                    key: $key,
                    action: ArtifactWriteAction::Skipped,
                    modelId: null,
                    notes: [__('i18n.backend.support.config_bundle.config_bundle_applier.the_artifact_is_not_in_the_package_nothing_will', ['value1' => $key])],
                );

                continue;
            }

            $writes[] = [
                'kind' => $kind,
                'artifact' => $artifact,
                'state' => $state === DiffState::Conflicted ? DiffState::Modified : $state,
                'rank' => $rank,
            ];
        }

        usort($removals, static fn (array $left, array $right): int => $right['rank'] <=> $left['rank']);
        usort($writes, static fn (array $left, array $right): int => $left['rank'] <=> $right['rank']);

        return ['removals' => $removals, 'writes' => $writes, 'skipped' => $skipped];
    }

    /**
     * @param  list<array{kind: ArtifactKind, artifact: BundleArtifact, state: DiffState, rank: int}>  $writes
     *
     * @throws UnresolvedPlaceholderException
     */
    private function refuseUnresolvedPlaceholders(array $writes): void
    {
        $carriers = [];

        foreach ($writes as $write) {
            if ($this->carriesPlaceholder($write['artifact'])) {
                $carriers[] = ['kind' => $write['kind'], 'key' => $write['artifact']->key];
            }
        }

        if ($carriers !== []) {
            throw UnresolvedPlaceholderException::forArtifacts($carriers);
        }
    }

    private function carriesPlaceholder(BundleArtifact $artifact): bool
    {
        $placeholder = config('engine.config_bundle.placeholder');

        if (!is_string($placeholder) || $placeholder === '') {
            return false;
        }

        $payload = $artifact->payload;
        $carriesPlaceholder = false;

        array_walk_recursive($payload, static function (mixed $value) use ($placeholder, &$carriesPlaceholder): void {
            $carriesPlaceholder = $carriesPlaceholder || (is_string($value) && str_contains($value, $placeholder));
        });

        return $carriesPlaceholder;
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     * @return list<ArtifactDiff>
     */
    private function undecidedOf(BundleDiff $diff, PromotionSelection $selection, array $decisions): array
    {
        return array_values(array_filter(
            $diff->conflicts(),
            static fn (ArtifactDiff $conflict): bool => $selection->contains($conflict->kind, $conflict->key)
                && !$conflict->isDecidable($decisions),
        ));
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     */
    private function withoutKeptTargets(PromotionSelection $selection, BundleDiff $diff, array $decisions): PromotionSelection
    {
        $kept = [];

        foreach ($diff->conflicts() as $conflict) {
            if ($this->decisionFor($decisions, $conflict)?->resolution === ConflictResolution::KeepTarget) {
                $kept[$conflict->kind->identifierFor($conflict->key)] = true;
            }
        }

        if ($kept === []) {
            return $selection;
        }

        return new PromotionSelection(array_values(array_filter(
            $selection->pairs,
            static fn (array $pair): bool => !isset($kept[$pair['kind']->identifierFor($pair['key'])]),
        )));
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     */
    private function decisionFor(array $decisions, ArtifactDiff $conflict): ?ConflictDecision
    {
        foreach ($decisions as $decision) {
            if ($decision->kind === $conflict->kind && $decision->key === $conflict->key) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * @return array<string, DiffState>
     */
    private function statesOf(BundleDiff $diff): array
    {
        $states = [];

        foreach ($diff->diffs as $entry) {
            $states[$entry->kind->identifierFor($entry->key)] = $entry->state;
        }

        return $states;
    }

    /**
     * @return array<string, int>
     */
    private function ranks(): array
    {
        $configured = config('engine.tenant_artifacts');
        $ranks = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            $kind = is_array($entry) && ($entry['bundle'] ?? false) === true ? $entry['kind'] ?? null : null;

            if (is_string($kind) && !isset($ranks[$kind])) {
                $ranks[$kind] = count($ranks);
            }
        }

        return $ranks;
    }

    /**
     * @return list<string>
     */
    private function notesOf(DependencyResolution $resolution): array
    {
        $notes = [];

        foreach ($resolution->pulledIn as $pull) {
            if ($pull['optional']) {
                $identifier = $pull['kind']->identifierFor($pull['key']);
                $notes[] = __('i18n.backend.support.config_bundle.config_bundle_applier.the_artifact_would_complement_the_selection_but_is_not', ['value1' => $identifier]);
            }
        }

        return $notes;
    }
}
