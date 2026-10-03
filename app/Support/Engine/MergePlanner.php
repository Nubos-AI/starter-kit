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
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeValueOrigin;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Database\Eloquent\Collection;

class MergePlanner
{
    public function __construct(
        private readonly MergeRuleResolver $ruleResolver,
        private readonly MergePreflight $preflight,
        private readonly MergeStrategyRegistry $strategies,
        private readonly MergeTransferCounter $transferCounter,
        private readonly TranslatableValueResolver $labels,
    ) {}

    public function plan(CustomRecord $target, CustomRecord $source, MergeRequestData $request): MergePlanData
    {
        $decision = $this->ruleResolver->resolve($target, $source);
        $blockers = $this->preflight->check($target, $source, $decision, $request);

        $fields = $this->fieldPlans($this->fieldsOf($target), $target, $source, $decision, $request);

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
    private function fieldsOf(CustomRecord $record): Collection
    {
        return FieldDefinition::query()
            ->where('object_type_id', $record->object_type_id)
            ->orderBy('list_position')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return list<MergeFieldPlan>
     */
    private function fieldPlans(
        Collection $fields,
        CustomRecord $target,
        CustomRecord $source,
        MergeRuleDecision $decision,
        MergeRequestData $request,
    ): array {
        $plans = [];

        foreach ($fields as $field) {
            $strategy = $decision->strategyFor($field);
            $context = new MergeValueContext($field, $target, $source);
            $outcome = $this->strategies->handlerFor($strategy)->resolve($context);
            $override = $request->overrideFor($field->key);

            if ($override instanceof MergeValueOrigin) {
                $outcome = new MergeValueOutcome(
                    $override === MergeValueOrigin::Source ? $context->sourceValue() : $context->targetValue(),
                    $override,
                );
            }

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
        $unique = FieldDefinition::query()
            ->where('object_type_id', $target->object_type_id)
            ->where('is_unique', true)
            ->where('is_encrypted', false)
            ->where('is_translatable', false)
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
