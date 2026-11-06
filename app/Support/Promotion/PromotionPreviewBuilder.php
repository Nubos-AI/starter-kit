<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\Contracts\Promotion\TenantPromotionSourceInterface;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\DependencyResolution;
use App\DTOs\Promotion\PromotionComparison;
use App\DTOs\Promotion\PromotionSelection;
use App\DTOs\Promotion\RenameHint;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnresolvedDependenciesException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Exceptions\Promotion\CyclicArtifactDependencyException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class PromotionPreviewBuilder
{
    public static string $notDraftMessage = 'i18n.backend.support.promotion.promotion_preview_builder.this_promotion_has_already_been_submitted_and_can_no';

    public static string $missingPermissionMessage = 'i18n.backend.support.promotion.promotion_preview_builder.you_do_not_have_permission_to_trigger_promotions';

    private string $emptySelectionMessage = 'i18n.backend.support.promotion.promotion_preview_builder.the_selection_is_empty_select_at_least_one_artifact';

    private string $outdatedDecisionMessage = 'i18n.backend.support.promotion.promotion_preview_builder.a_saved_conflict_decision_no_longer_matches_the_current';

    private string $missingPrerequisiteMessage = 'i18n.backend.support.promotion.promotion_preview_builder.the_selection_requires_prerequisites_that_are_not_included_yet';

    public function __construct(
        private readonly PromotionSourceResolver $sourceResolver,
        private readonly TenantPromotionSourceInterface $tenantSource,
        private readonly ConfigBundleSerializer $serializer,
        private readonly BundleDiffer $differ,
        private readonly RenameDetector $renameDetector,
        private readonly ArtifactDependencyResolver $dependencyResolver,
        private readonly PromotionTargetRefusals $targetRefusals,
    ) {}

    /**
     * @throws PromotionSourceUnavailableException
     * @throws MalformedBundleException
     * @throws Throwable
     */
    public function compare(PromotionRun $run): PromotionComparison
    {
        $source = $this->sourceResolver->resolve($run);
        $target = $this->serializer->serialize($run->tenant_id);

        return new PromotionComparison($source, $target, $this->differ->diff($source, $target, $run->counterpart_key));
    }

    /**
     * @return array{
     *     groups: list<array{kind: ArtifactKind, unchanged_count: int, rename_hints: list<RenameHint>, rows: list<array{diff: ArtifactDiff, is_selected: bool, is_pulled_in: bool, refusal_reason: string|null, is_refused_by_target: bool, overwrite_consequence: string|null, is_overwritten: bool, decision: ConflictResolution|null}>}>,
     *     submit_blocker: string|null,
     *     has_outdated_decisions: bool,
     *     undecided_count: int,
     *     source_error: string|null,
     * }
     *
     * @throws JsonException
     * @throws Throwable
     */
    public function build(PromotionRun $run): array
    {
        try {
            $comparison = $this->compare($run);
            $targetRefusals = $this->targetRefusals->of($run, $comparison);
            $blocking = $this->targetRefusals->blocking($targetRefusals, $run->overwrite_selection);
            $resolution = $this->dependencyResolver->resolve($run->hand_selection, $comparison->source, $comparison->diff, $blocking);
            $blocker = $this->submissionBlocker($run, $comparison);
        } catch (PromotionSourceUnavailableException|MalformedBundleException|UnsupportedBundleSchemaVersionException|CyclicArtifactDependencyException $exception) {
            return [
                'groups' => [],
                'submit_blocker' => $exception->getMessage(),
                'has_outdated_decisions' => false,
                'undecided_count' => 0,
                'source_error' => $exception->getMessage(),
            ];
        }

        return [
            'groups' => $this->groupsOf($run, $comparison, $resolution, $targetRefusals),
            'submit_blocker' => $blocker?->getMessage(),
            'has_outdated_decisions' => $this->hasOutdatedDecisions($run, $comparison),
            'undecided_count' => count($this->undecidedConflicts($run, $comparison)),
            'source_error' => null,
        ];
    }

    public function creationRefusal(User $actingUser): ?string
    {
        try {
            $this->tenantSource->assertContextAllowed();

            if (!$actingUser->hasPermission('promotions.execute')) {
                return __(self::$missingPermissionMessage);
            }

            $source = $this->tenantSource->sourceFor($actingUser);

            $this->sourceResolver->assertResolvable(PromotionRun::query()->make([
                'tenant_id' => (string) $actingUser->tenant_id,
                'source_tenant_id' => (string) $source->getKey(),
                'direction' => PromotionDirection::TenantToProduction,
            ]));
        } catch (PromotionSourceUnavailableException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function assertDraft(PromotionRun $run): void
    {
        if ($run->status !== PromotionRunStatus::Draft) {
            throw ValidationException::withMessages(['status' => __(self::$notDraftMessage)]);
        }
    }

    public function submissionBlocker(PromotionRun $run, PromotionComparison $comparison): ValidationException|UndecidedConflictsException|UnresolvedDependenciesException|null
    {
        if ($run->status !== PromotionRunStatus::Draft) {
            return ValidationException::withMessages(['status' => __(self::$notDraftMessage)]);
        }

        $selection = $run->promotion_selection;

        if ($selection->count() === 0) {
            return ValidationException::withMessages(['selection' => __($this->emptySelectionMessage)]);
        }

        $undecided = $this->undecidedConflicts($run, $comparison);

        if ($undecided !== []) {
            return UndecidedConflictsException::forUndecided($undecided);
        }

        if ($this->hasOutdatedDecisions($run, $comparison)) {
            return ValidationException::withMessages(['conflicts' => __($this->outdatedDecisionMessage)]);
        }

        $blocking = $this->targetRefusals->blocking($this->targetRefusals->of($run, $comparison), $run->overwrite_selection);
        $resolution = $this->dependencyResolver->resolve($selection, $comparison->source, $comparison->diff, $blocking);

        if ($resolution->refusals !== []) {
            return UnresolvedDependenciesException::forRefusals($resolution->refusals);
        }

        if ($this->requiredPulledIn($resolution)->count() > 0) {
            return ValidationException::withMessages(['selection' => __($this->missingPrerequisiteMessage)]);
        }

        return null;
    }

    public function requiredPulledIn(DependencyResolution $resolution): PromotionSelection
    {
        $required = new PromotionSelection([]);

        foreach ($resolution->pulledIn as $pull) {
            if (!$pull['optional']) {
                $required = $required->withAdded($pull['kind'], $pull['key']);
            }
        }

        return $required;
    }

    /**
     * @param  list<array{kind: ArtifactKind, key: string, reason: string, overwrite_consequence: string|null}>  $targetRefusals
     * @return list<array{kind: ArtifactKind, unchanged_count: int, rename_hints: list<RenameHint>, rows: list<array{diff: ArtifactDiff, is_selected: bool, is_pulled_in: bool, refusal_reason: string|null, is_refused_by_target: bool, overwrite_consequence: string|null, is_overwritten: bool, decision: ConflictResolution|null}>}>
     *
     * @throws JsonException
     */
    private function groupsOf(PromotionRun $run, PromotionComparison $comparison, DependencyResolution $resolution, array $targetRefusals): array
    {
        $hand = $run->hand_selection;
        $required = $this->requiredPulledIn($resolution);
        $hints = $this->renameDetector->detect($comparison->diff, $comparison->source, $comparison->target);

        $overwrites = $run->overwrite_selection;
        $refusals = [];
        $targetRefused = [];
        $consequences = [];

        foreach ($targetRefusals as $refusal) {
            $identifier = $refusal['kind']->identifierFor($refusal['key']);
            $consequences[$identifier] = $refusal['overwrite_consequence'];

            if ($refusal['overwrite_consequence'] === null || !$overwrites->contains($refusal['kind'], $refusal['key'])) {
                $refusals[$identifier] = $refusal['reason'];
                $targetRefused[$identifier] = true;
            }
        }

        foreach ($resolution->refusals as $refusal) {
            $refusals[$refusal['kind']->identifierFor($refusal['key'])] = $refusal['reason'];
        }

        $decisions = [];

        foreach ($run->conflict_decision_list as $decision) {
            $decisions[$decision->kind->identifierFor($decision->key)] = $decision->resolution;
        }

        $groups = [];

        foreach (ArtifactKind::cases() as $kind) {
            $entries = array_values(array_filter($comparison->diff->diffs, static fn (ArtifactDiff $entry): bool => $entry->kind === $kind));
            $changed = array_values(array_filter($entries, static fn (ArtifactDiff $entry): bool => $entry->state !== DiffState::Unchanged));

            if ($changed === []) {
                continue;
            }

            $groups[] = [
                'kind' => $kind,
                'unchanged_count' => count($entries) - count($changed),
                'rename_hints' => array_values(array_filter($hints, static fn (RenameHint $hint): bool => $hint->kind === $kind)),
                'rows' => array_map(static function (ArtifactDiff $entry) use ($hand, $required, $refusals, $targetRefused, $consequences, $overwrites, $decisions): array {
                    $identifier = $entry->kind->identifierFor($entry->key);
                    $isHandPicked = $hand->contains($entry->kind, $entry->key);
                    $isPulledIn = !$isHandPicked && $required->contains($entry->kind, $entry->key);

                    return [
                        'diff' => $entry,
                        'is_selected' => $isHandPicked || $isPulledIn,
                        'is_pulled_in' => $isPulledIn,
                        'refusal_reason' => $refusals[$identifier] ?? null,
                        'is_refused_by_target' => isset($targetRefused[$identifier]),
                        'overwrite_consequence' => $consequences[$identifier] ?? null,
                        'is_overwritten' => isset($consequences[$identifier]) && $overwrites->contains($entry->kind, $entry->key),
                        'decision' => $entry->state === DiffState::Conflicted ? ($decisions[$identifier] ?? null) : null,
                    ];
                }, $changed),
            ];
        }

        return $groups;
    }

    /**
     * @return list<ArtifactDiff>
     */
    private function undecidedConflicts(PromotionRun $run, PromotionComparison $comparison): array
    {
        $selection = $run->promotion_selection;
        $decisions = $run->conflict_decision_list;

        return array_values(array_filter(
            $comparison->diff->conflicts(),
            static fn (ArtifactDiff $conflict): bool => $selection->contains($conflict->kind, $conflict->key) && !$conflict->isDecidable($decisions),
        ));
    }

    private function hasOutdatedDecisions(PromotionRun $run, PromotionComparison $comparison): bool
    {
        foreach ($run->conflict_decision_list as $decision) {
            if ($comparison->entry($decision->kind, $decision->key)?->state !== DiffState::Conflicted) {
                return true;
            }
        }

        return false;
    }
}
