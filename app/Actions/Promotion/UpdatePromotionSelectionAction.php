<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\DTOs\Promotion\ConflictDecision;
use App\DTOs\Promotion\DependencyResolution;
use App\DTOs\Promotion\PromotionComparison;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\ConflictResolution;
use App\Enums\Promotion\DiffState;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Promotion\ArtifactDependencyResolver;
use App\Support\Promotion\PromotionPreviewBuilder;
use App\Support\Promotion\PromotionTargetRefusals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdatePromotionSelectionAction
{
    public function __construct(
        private readonly PromotionPreviewBuilder $previewBuilder,
        private readonly ArtifactDependencyResolver $dependencyResolver,
        private readonly PromotionTargetRefusals $targetRefusals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actingUser, PromotionRun $run, array $input): DependencyResolution
    {
        $validated = Validator::make($input, [
            'mode' => ['required', Rule::in(['all', 'selected'])],
            'selection' => ['exclude_unless:mode,selected', 'present', 'array'],
            'selection.*.artifactKind' => ['required', Rule::enum(ArtifactKind::class)],
            'selection.*.artifactKey' => ['required', 'string'],
            'selection.*.overwrite' => ['sometimes', 'boolean'],
            'conflictDecisions' => ['array'],
            'conflictDecisions.*.artifactKind' => ['required', Rule::enum(ArtifactKind::class)],
            'conflictDecisions.*.artifactKey' => ['required', 'string'],
            'conflictDecisions.*.decision' => ['required', Rule::enum(ConflictResolution::class)],
        ], [], [
            'mode' => __('i18n.backend.actions.promotion.update_promotion_selection_action.mode'),
            'selection' => __('i18n.backend.actions.promotion.update_promotion_selection_action.selection'),
            'conflictDecisions' => __('i18n.backend.actions.promotion.update_promotion_selection_action.conflict_decisions'),
        ])->validate();

        $this->previewBuilder->assertDraft($run);

        $comparison = $this->previewBuilder->compare($run);
        $targetRefusals = $this->targetRefusals->of($run, $comparison);

        /** @var list<array{artifactKind: string, artifactKey: string, overwrite?: bool}> $requested */
        $requested = $validated['selection'] ?? [];

        $overwrites = $validated['mode'] === 'all'
            ? $run->overwrite_selection
            : $this->requestedOverwritesOf($requested, $targetRefusals);

        $blocking = $this->targetRefusals->blocking($targetRefusals, $overwrites);

        $hand = $validated['mode'] === 'all'
            ? $this->changedSelectionOf($comparison, $blocking)
            : $this->requestedSelectionOf($requested, $comparison);

        $resolution = $this->dependencyResolver->resolve($hand, $comparison->source, $comparison->diff, $blocking);
        $required = $this->previewBuilder->requiredPulledIn($resolution);

        /** @var list<array{artifactKind: string, artifactKey: string, decision: string}> $decisionInput */
        $decisionInput = $validated['conflictDecisions'] ?? [];

        $decisions = $this->decisionsOf($actingUser, $decisionInput, $comparison, $hand, $required);
        $selection = $this->storedSelectionOf($hand, $required, $overwrites);

        DB::transaction(function () use ($run, $selection, $decisions): void {
            $locked = PromotionRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            $this->previewBuilder->assertDraft($locked);

            $locked->fill(['selection' => $selection, 'conflict_decisions' => $decisions])->save();
        });

        return $resolution;
    }

    /**
     * @param  list<array{artifactKind: string, artifactKey: string, overwrite?: bool}>  $requested
     * @param  list<array{kind: ArtifactKind, key: string, reason: string, overwrite_consequence: string|null}>  $targetRefusals
     *
     * @throws ValidationException
     */
    private function requestedOverwritesOf(array $requested, array $targetRefusals): PromotionSelection
    {
        $overwrites = new PromotionSelection([]);

        foreach ($requested as $pair) {
            if (($pair['overwrite'] ?? false) !== true) {
                continue;
            }

            $kind = ArtifactKind::from($pair['artifactKind']);
            $overwritable = array_filter(
                $targetRefusals,
                static fn (array $refusal): bool => $refusal['kind'] === $kind
                    && $refusal['key'] === $pair['artifactKey']
                    && $refusal['overwrite_consequence'] !== null,
            );

            if ($overwritable === []) {
                throw ValidationException::withMessages([
                    'selection' => __('i18n.backend.actions.promotion.update_promotion_selection_action.cannot_be_overwritten_no_deleted_entry_exists_for_it', ['value1' => $kind->identifierFor($pair['artifactKey'])]),
                ]);
            }

            $overwrites = $overwrites->withAdded($kind, $pair['artifactKey']);
        }

        return $overwrites;
    }

    /**
     * @param  list<array{kind: ArtifactKind, key: string, reason: string, overwrite_consequence: string|null}>  $targetRefusals
     */
    private function changedSelectionOf(PromotionComparison $comparison, array $targetRefusals): PromotionSelection
    {
        $refused = new PromotionSelection([]);

        foreach ($targetRefusals as $refusal) {
            $refused = $refused->withAdded($refusal['kind'], $refusal['key']);
        }

        $selection = new PromotionSelection([]);

        foreach ($comparison->diff->diffs as $entry) {
            if ($entry->state !== DiffState::Unchanged && !$refused->contains($entry->kind, $entry->key)) {
                $selection = $selection->withAdded($entry->kind, $entry->key);
            }
        }

        return $selection;
    }

    /**
     * @param  list<array{artifactKind: string, artifactKey: string}>  $requested
     *
     * @throws ValidationException
     */
    private function requestedSelectionOf(array $requested, PromotionComparison $comparison): PromotionSelection
    {
        $selection = new PromotionSelection([]);

        foreach ($requested as $pair) {
            $kind = ArtifactKind::from($pair['artifactKind']);
            $state = $comparison->entry($kind, $pair['artifactKey'])?->state;

            if ($state === null || $state === DiffState::Unchanged) {
                throw ValidationException::withMessages([
                    'selection' => __('i18n.backend.actions.promotion.update_promotion_selection_action.the_artifact_is_not_among_the_differences_in_the', ['value1' => $kind->identifierFor($pair['artifactKey'])]),
                ]);
            }

            $selection = $selection->withAdded($kind, $pair['artifactKey']);
        }

        return $selection;
    }

    /**
     * @param  list<array{artifactKind: string, artifactKey: string, decision: string}>  $decisionInput
     * @return list<array{kind: string, key: string, resolution: string, decided_by_id: string, decided_at: string}>
     *
     * @throws ValidationException
     */
    private function decisionsOf(User $actingUser, array $decisionInput, PromotionComparison $comparison, PromotionSelection $hand, PromotionSelection $required): array
    {
        $decisions = [];

        foreach ($decisionInput as $input) {
            $kind = ArtifactKind::from($input['artifactKind']);
            $key = $input['artifactKey'];
            $identifier = $kind->identifierFor($key);

            if ($comparison->entry($kind, $key)?->state !== DiffState::Conflicted) {
                throw ValidationException::withMessages([
                    'conflictDecisions' => __('i18n.backend.actions.promotion.update_promotion_selection_action.there_is_no_conflict_to_resolve_for_in_the', ['value1' => $identifier]),
                ]);
            }

            if (!$hand->contains($kind, $key) && !$required->contains($kind, $key)) {
                continue;
            }

            $decisions[$identifier] = (new ConflictDecision(
                kind: $kind,
                key: $key,
                resolution: ConflictResolution::from($input['decision']),
                decidedById: (string) $actingUser->getKey(),
                decidedAt: CarbonImmutable::now(),
            ))->toArray();
        }

        return array_values($decisions);
    }

    /**
     * @return list<array{kind: string, key: string, pulled_in?: true, overwrite?: true}>
     */
    private function storedSelectionOf(PromotionSelection $hand, PromotionSelection $required, PromotionSelection $overwrites): array
    {
        $stored = array_map(
            static fn (array $pair): array => $overwrites->contains($pair['kind'], $pair['key'])
                ? ['kind' => $pair['kind']->value, 'key' => $pair['key'], 'overwrite' => true]
                : ['kind' => $pair['kind']->value, 'key' => $pair['key']],
            $hand->pairs,
        );

        foreach ($required->pairs as $pair) {
            if (!$hand->contains($pair['kind'], $pair['key'])) {
                $stored[] = ['kind' => $pair['kind']->value, 'key' => $pair['key'], 'pulled_in' => true];
            }
        }

        return $stored;
    }
}
