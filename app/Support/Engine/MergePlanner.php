<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\MergeBlocker;
use App\DTOs\Engine\MergeFieldPlan;
use App\DTOs\Engine\MergePlanData;
use App\DTOs\Engine\MergeRequestData;
use App\DTOs\Engine\MergeRuleDecision;
use App\DTOs\Engine\MergeTransferPlan;
use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeBlockReason;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeValueOrigin;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class MergePlanner
{
    public function __construct(
        private readonly MergeRuleResolver $ruleResolver,
        private readonly MergePreflight $preflight,
        private readonly MergeStrategyRegistry $strategies,
        private readonly MergeTransferCounter $transferCounter,
        private readonly TranslatableValueResolver $labels,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    public function plan(CustomRecord $target, CustomRecord $source, MergeRequestData $request): MergePlanData
    {
        $decision = $this->ruleResolver->resolve($target, $source);
        $blockers = $this->preflight->check($target, $source, $decision, $request);

        $fields = $this->fieldPlans(
            $this->readableFieldsOf($target),
            $target,
            $source,
            $decision,
            $request,
            $this->forbiddenWriteKeys($target),
        );

        return new MergePlanData(
            (string) $target->getKey(),
            (string) $source->getKey(),
            $target->version,
            $source->version,
            $decision->mode,
            $decision->rule === null ? null : (string) $decision->rule->getKey(),
            $decision->rule?->name,
            $decision->denyReason,
            $decision->requiresReason(),
            $fields,
            $this->transferPlans($source, $decision),
            [
                ...$blockers,
                ...$this->decisionBlockers($fields),
                ...$this->uniqueBlockers($fields, $target, $source),
            ],
        );
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function readableFieldsOf(CustomRecord $record): Collection
    {
        $actor = Auth::user();

        $forbidden = $actor instanceof User
            ? FieldVisibilityResolver::forRequest()->forbiddenReadFieldKeys($actor, $record->object_type_id)
            : [];

        return $this->fieldLookup->fields($record->object_type_id)
            ->reject(static fn (FieldDefinition $field): bool => in_array($field->key, $forbidden, true))
            ->sortBy([
                ['list_position', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();
    }

    /**
     * @return list<string>
     */
    private function forbiddenWriteKeys(CustomRecord $record): array
    {
        $actor = Auth::user();

        return $actor instanceof User
            ? FieldVisibilityResolver::forRequest()->forbiddenWriteFieldKeys($actor, $record->object_type_id)
            : [];
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @param  list<string>  $forbiddenWriteKeys
     * @return list<MergeFieldPlan>
     */
    private function fieldPlans(
        Collection $fields,
        CustomRecord $target,
        CustomRecord $source,
        MergeRuleDecision $decision,
        MergeRequestData $request,
        array $forbiddenWriteKeys,
    ): array {
        $plans = [];

        foreach ($fields as $field) {
            $strategy = $decision->strategyFor($field);
            $context = new MergeValueContext($field, $target, $source);
            $isWritable = !$field->field_type->isDerived() && !in_array($field->key, $forbiddenWriteKeys, true);
            $override = $isWritable ? $request->overrideFor($field->key) : null;
            $outcome = $this->outcomeFor($context, $strategy, $override, $isWritable);

            $label = $this->labels->resolve($field->i18n_labels);

            $plans[] = new MergeFieldPlan(
                $field->key,
                is_string($label) && $label !== '' ? $label : $field->key,
                $field->field_type,
                $strategy,
                $context->targetValue(),
                $context->sourceValue(),
                $outcome->value,
                $outcome->origin,
                $this->isConflict($context),
                $outcome->requiresDecision,
                $override instanceof MergeValueOrigin,
            );
        }

        return $plans;
    }

    private function outcomeFor(
        MergeValueContext $context,
        MergeFieldStrategy $strategy,
        ?MergeValueOrigin $override,
        bool $isWritable,
    ): MergeValueOutcome {
        if (!$isWritable) {
            return new MergeValueOutcome($context->targetValue(), MergeValueOrigin::Target);
        }

        if ($override instanceof MergeValueOrigin) {
            return new MergeValueOutcome(
                $override === MergeValueOrigin::Source ? $context->sourceValue() : $context->targetValue(),
                $override,
            );
        }

        return $this->strategies->handlerFor($strategy)->resolve($context);
    }

    private function isConflict(MergeValueContext $context): bool
    {
        return !$context->targetIsEmpty()
            && !$context->sourceIsEmpty()
            && $context->targetValue() !== $context->sourceValue();
    }

    /**
     * @return list<MergeTransferPlan>
     */
    private function transferPlans(CustomRecord $source, MergeRuleDecision $decision): array
    {
        return array_map(
            fn (MergeTransferCategory $category): MergeTransferPlan => new MergeTransferPlan(
                $category,
                $decision->policyFor($category),
                $this->transferCounter->count($category, $source),
            ),
            MergeTransferCategory::cases(),
        );
    }

    /**
     * @param  list<MergeFieldPlan>  $fields
     * @return list<MergeBlocker>
     */
    private function decisionBlockers(array $fields): array
    {
        $open = array_values(array_filter(
            $fields,
            static fn (MergeFieldPlan $field): bool => $field->requiresDecision,
        ));

        return array_map(
            static fn (MergeFieldPlan $field): MergeBlocker => new MergeBlocker(
                MergeBlockReason::DecisionMissing,
                $field->key,
            ),
            $open,
        );
    }

    /**
     * @param  list<MergeFieldPlan>  $fields
     * @return list<MergeBlocker>
     */
    private function uniqueBlockers(array $fields, CustomRecord $target, CustomRecord $source): array
    {
        $unique = $this->fieldLookup->fields($target->object_type_id)
            ->filter(static fn (FieldDefinition $field): bool => $field->is_unique
                && !$field->is_encrypted
                && !$field->is_translatable)
            ->pluck('key')
            ->all();

        if ($unique === []) {
            return [];
        }

        $blockers = [];

        foreach ($fields as $field) {
            if (!in_array($field->key, $unique, true) || MergeValueContext::isEmpty($field->resultValue)) {
                continue;
            }

            $taken = CustomRecord::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $target->tenant_id)
                ->ofType($target->object_type_id)
                ->whereNull('deleted_at')
                ->whereNotIn('id', [$target->getKey(), $source->getKey()])
                ->whereField($field->key, $field->resultValue)
                ->exists();

            if ($taken) {
                $blockers[] = new MergeBlocker(MergeBlockReason::UniqueFieldConflict, $field->key);
            }
        }

        return $blockers;
    }
}
