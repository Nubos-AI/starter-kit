<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeTransferPolicy;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\I18n\TranslatableValueResolver;

class MergeRuleEditorOptions
{
    public function __construct(
        private readonly ConditionFieldPresenter $conditionFieldPresenter,
        private readonly TranslatableValueResolver $labels,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(User $user, ObjectType $objectType): array
    {
        return [
            'mergeConditionFields' => $this->conditionFields($user, $objectType),
            'mergeFieldStrategyOptions' => $this->fieldStrategyOptions($objectType),
            'mergeTransferCategories' => $this->transferCategories(),
            'mergeActiveRuleLimit' => MergeRuleValidator::$activeRuleLimit,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conditionFields(User $user, ObjectType $objectType): array
    {
        return $this->conditionFieldPresenter->forObjectType($user, $objectType);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldStrategyOptions(ObjectType $objectType): array
    {
        $fields = FieldDefinition::query()
            ->where('object_type_id', (string) $objectType->getKey())
            ->orderBy('list_position')
            ->orderBy('created_at')
            ->get();

        $options = [];

        foreach ($fields as $field) {
            $label = $this->labels->resolve($field->i18n_labels);

            $options[] = [
                'key' => $field->key,
                'label' => is_string($label) && $label !== '' ? $label : $field->key,
                'field_type' => $field->field_type->value,
                'strategies' => array_map(
                    static fn (MergeFieldStrategy $strategy): string => $strategy->value,
                    MergeFieldStrategy::supportedBy($field->field_type),
                ),
            ];
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transferCategories(): array
    {
        return array_map(
            static fn (MergeTransferCategory $category): array => [
                'value' => $category->value,
                'default_policy' => $category->defaultPolicy()->value,
                'policies' => array_map(
                    static fn (MergeTransferPolicy $policy): string => $policy->value,
                    $category->allowedPolicies(),
                ),
            ],
            MergeTransferCategory::cases(),
        );
    }
}
