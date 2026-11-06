<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeTransferPolicy;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\FieldDefinition;
use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MergeRuleValidator
{
    public static int $activeRuleLimit = 20;

    /**
     * @var list<string>
     */
    private array $optionKeys = [
        'requires_reason',
        'requires_dedup_match',
        'inherits_external_reference',
        'blocks_on_running_automations',
    ];

    public function __construct(
        private readonly FilterTreeValidator $filterTreeValidator,
        private readonly SystemFilterFields $systemFilterFields,
    ) {
        foreach (config('modules.merge.option_keys', []) as $key) {
            if (is_string($key) && !in_array($key, $this->optionKeys, true)) {
                $this->optionKeys[] = $key;
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(ObjectType $objectType, ?MergeRule $rule): array
    {
        $unique = Rule::unique('merge_rules', 'name')
            ->where('object_type_id', (string) $objectType->getKey())
            ->whereNull('deleted_at');

        if ($rule instanceof MergeRule) {
            $unique->ignore($rule);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'mode' => ['required', Rule::enum(MergeRuleMode::class)],
            'position' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'deny_reason' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'array'],
            'field_strategies' => ['array'],
            'field_strategies.*' => [Rule::enum(MergeFieldStrategy::class)],
            'transfer_policy' => ['array'],
            'transfer_policy.*' => [Rule::enum(MergeTransferPolicy::class)],
            'options' => ['array'],
            'options.*' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertValid(ObjectType $objectType, array $validated, ?MergeRule $rule): void
    {
        $mode = MergeRuleMode::from((string) $validated['mode']);

        $this->assertDenyReason($mode, $validated);
        $this->assertOptionKeys($validated);

        if ($mode === MergeRuleMode::Allow) {
            $this->assertFieldStrategies($objectType, $validated);
            $this->assertTransferPolicy($validated);
        }

        $this->assertCondition($objectType, $validated);
        $this->assertActiveRuleLimit($objectType, $validated, $rule);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalize(array $validated): array
    {
        $mode = MergeRuleMode::from((string) $validated['mode']);

        $validated['condition'] = $this->normalizeCondition($validated['condition'] ?? null);
        $validated['options'] = $this->normalizeOptions($validated['options'] ?? []);

        if ($mode === MergeRuleMode::Deny) {
            $validated['field_strategies'] = [];
            $validated['transfer_policy'] = [];

            return $validated;
        }

        $validated['deny_reason'] = null;
        $validated['field_strategies'] = $this->normalizeStringMap($validated['field_strategies'] ?? []);
        $validated['transfer_policy'] = $this->normalizeStringMap($validated['transfer_policy'] ?? []);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertDenyReason(MergeRuleMode $mode, array $validated): void
    {
        $reason = $validated['deny_reason'] ?? null;
        $given = is_string($reason) && trim($reason) !== '';

        if ($mode === MergeRuleMode::Deny && !$given) {
            throw ValidationException::withMessages([
                'deny_reason' => __('i18n.backend.support.engine.merge_rule_validator.a_rule_preventing_merging_must_specify_the_reason_shown'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertOptionKeys(array $validated): void
    {
        $options = $validated['options'] ?? [];

        if (!is_array($options)) {
            return;
        }

        foreach (array_keys($options) as $key) {
            if (!in_array((string) $key, $this->optionKeys, true)) {
                throw ValidationException::withMessages([
                    'options' => __('i18n.backend.support.engine.merge_rule_validator.the_option_does_not_exist', ['value1' => $key]),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertFieldStrategies(ObjectType $objectType, array $validated): void
    {
        $strategies = $validated['field_strategies'] ?? [];

        if (!is_array($strategies) || $strategies === []) {
            return;
        }

        $fields = FieldDefinition::query()
            ->where('object_type_id', (string) $objectType->getKey())
            ->get()
            ->keyBy('key');

        foreach ($strategies as $key => $value) {
            $field = $fields->get((string) $key);

            if (!$field instanceof FieldDefinition) {
                throw ValidationException::withMessages([
                    'field_strategies' => __('i18n.backend.support.engine.merge_rule_validator.the_field_does_not_belong_to_this_object_type', ['value1' => $key]),
                ]);
            }

            $strategy = MergeFieldStrategy::from((string) $value);

            if (!$strategy->supports($field->field_type)) {
                throw ValidationException::withMessages([
                    'field_strategies' => __('i18n.backend.support.engine.merge_rule_validator.the_strategy_does_not_match_the_type_of_field', ['value1' => $strategy->value, 'value2' => $key]),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertTransferPolicy(array $validated): void
    {
        $policies = $validated['transfer_policy'] ?? [];

        if (!is_array($policies) || $policies === []) {
            return;
        }

        foreach ($policies as $key => $value) {
            $category = MergeTransferCategory::tryFrom((string) $key);

            if (!$category instanceof MergeTransferCategory) {
                throw ValidationException::withMessages([
                    'transfer_policy' => __('i18n.backend.support.engine.merge_rule_validator.the_category_does_not_exist', ['value1' => $key]),
                ]);
            }

            $policy = MergeTransferPolicy::from((string) $value);

            if (!$category->allows($policy)) {
                throw ValidationException::withMessages([
                    'transfer_policy' => __('i18n.backend.support.engine.merge_rule_validator.the_category_does_not_support_the_rule', ['value1' => $category->value, 'value2' => $policy->value]),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertCondition(ObjectType $objectType, array $validated): void
    {
        $condition = $validated['condition'] ?? null;

        if (!is_array($condition) || $condition === []) {
            return;
        }

        /** @var array<string, mixed> $condition */
        try {
            $this->filterTreeValidator->validate(
                $condition,
                $this->allowedFields($objectType),
                FieldVisibilityResolver::forRequest(),
                $objectType,
            );
        } catch (AuthorizationException|InvalidFilterTreeException $exception) {
            throw ValidationException::withMessages([
                'condition' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertActiveRuleLimit(ObjectType $objectType, array $validated, ?MergeRule $rule): void
    {
        $isActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : !$rule instanceof MergeRule || $rule->is_active;

        if (!$isActive) {
            return;
        }

        $query = MergeRule::query()
            ->where('object_type_id', (string) $objectType->getKey())
            ->where('is_active', true);

        if ($rule instanceof MergeRule) {
            $query->whereKeyNot($rule->getKey());
        }

        if ($query->count() >= self::$activeRuleLimit) {
            throw ValidationException::withMessages([
                'is_active' => __('i18n.backend.support.engine.merge_rule_validator.this_object_type_has_reached_the_maximum_number_of').self::$activeRuleLimit.').',
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeCondition(mixed $condition): ?array
    {
        if (!is_array($condition) || $condition === []) {
            return null;
        }

        /** @var array<string, mixed> $condition */
        return $condition;
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeOptions(mixed $options): array
    {
        $normalized = [];

        foreach ($this->optionKeys as $key) {
            if (is_array($options) && array_key_exists($key, $options)) {
                $normalized[$key] = (bool) $options[$key];
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }

        $normalized = [];

        foreach ($map as $key => $value) {
            $normalized[(string) $key] = (string) $value;
        }

        return $normalized;
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function allowedFields(ObjectType $objectType): Collection
    {
        $objectTypeId = (string) $objectType->getKey();

        return FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('is_filterable', true)
            ->get()
            ->toBase()
            ->concat($this->systemFilterFields->all($objectTypeId));
    }
}
