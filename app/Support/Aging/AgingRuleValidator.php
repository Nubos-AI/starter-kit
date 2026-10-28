<?php

declare(strict_types=1);

namespace App\Support\Aging;

use App\Enums\Engine\AgingClock;
use App\Enums\Engine\AgingThresholdColor;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\AgingRule;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgingRuleValidator
{
    public static int $activeRuleLimit = 20;

    public function __construct(
        private readonly FilterFieldKeyCollector $filterFieldKeyCollector,
        private readonly FilterTreeValidator $filterTreeValidator,
        private readonly SystemFilterFields $systemFilterFields,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(ObjectType $objectType, ?AgingRule $rule): array
    {
        $unique = Rule::unique('aging_rules', 'name')
            ->where('object_type_id', (string) $objectType->getKey())
            ->whereNull('deleted_at');

        if ($rule instanceof AgingRule) {
            $unique->ignore($rule);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'clock' => ['required', Rule::in(['updated_at', 'field', ...array_keys(config('modules.aging.clocks', []))])],
            'clock_field_key' => ['nullable', 'string'],
            'condition' => ['nullable', 'array'],
            'thresholds' => ['required', 'array', 'min:1'],
            'thresholds.*.after_days' => ['required', 'integer', 'min:1'],
            'thresholds.*.color' => ['required', Rule::enum(AgingThresholdColor::class)],
            'is_active' => ['boolean'],
            'triggers_automation' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertValid(ObjectType $objectType, array $validated, ?AgingRule $rule): void
    {
        $this->assertClockFieldKey($objectType, $validated);
        $this->assertThresholdsAscending($validated);
        $this->assertCondition($objectType, $validated);
        $this->assertActiveRuleLimit($objectType, $validated, $rule);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalize(array $validated): array
    {
        $raw = $validated['thresholds'] ?? [];
        $thresholds = [];

        if (is_array($raw)) {
            foreach ($raw as $threshold) {
                if (!is_array($threshold)) {
                    continue;
                }

                $thresholds[] = [
                    'after_days' => (int) $threshold['after_days'],
                    'color' => (string) $threshold['color'],
                ];
            }
        }

        $validated['thresholds'] = $thresholds;

        if (AgingClock::from((string) $validated['clock']) !== AgingClock::Field) {
            $validated['clock_field_key'] = null;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertClockFieldKey(ObjectType $objectType, array $validated): void
    {
        $raw = $validated['clock_field_key'] ?? null;
        $key = is_string($raw) && $raw !== '' ? $raw : null;

        if (AgingClock::from((string) $validated['clock']) !== AgingClock::Field) {
            if ($key !== null) {
                throw ValidationException::withMessages([
                    'clock_field_key' => __('i18n.backend.support.aging.aging_rule_validator.a_measured_field_is_only_valid_when_using_a'),
                ]);
            }

            return;
        }

        if ($key === null) {
            throw ValidationException::withMessages([
                'clock_field_key' => __('i18n.backend.support.aging.aging_rule_validator.a_rule_measuring_a_custom_date_field_requires_that'),
            ]);
        }

        $field = FieldDefinition::query()
            ->where('object_type_id', (string) $objectType->getKey())
            ->where('key', $key)
            ->first();

        $usable = $field instanceof FieldDefinition && $field->isUsableDateField();

        if (!$usable) {
            throw ValidationException::withMessages([
                'clock_field_key' => __('i18n.backend.support.aging.aging_rule_validator.the_measured_field_must_be_a_date_field_of'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertThresholdsAscending(array $validated): void
    {
        $thresholds = $validated['thresholds'] ?? [];

        if (!is_array($thresholds)) {
            return;
        }

        $previous = null;

        foreach ($thresholds as $threshold) {
            if (!is_array($threshold)) {
                continue;
            }

            $afterDays = (int) $threshold['after_days'];

            if ($previous !== null && $afterDays <= $previous) {
                throw ValidationException::withMessages([
                    'thresholds' => __('i18n.backend.support.aging.aging_rule_validator.thresholds_must_be_specified_in_strictly_ascending_order_of'),
                ]);
            }

            $previous = $afterDays;
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
        $keys = $this->filterFieldKeyCollector->collect($condition);

        foreach ($keys as $key) {
            if ($this->systemFilterFields->isAgingField($key)) {
                throw ValidationException::withMessages([
                    'condition' => __('i18n.backend.support.aging.aging_rule_validator.the_condition_may_not_read_the_aging_field_a', ['value1' => $key]),
                ]);
            }
        }

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
    private function assertActiveRuleLimit(ObjectType $objectType, array $validated, ?AgingRule $rule): void
    {
        $isActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : !$rule instanceof AgingRule || $rule->is_active;

        if (!$isActive) {
            return;
        }

        $query = AgingRule::query()
            ->where('object_type_id', (string) $objectType->getKey())
            ->where('is_active', true);

        if ($rule instanceof AgingRule) {
            $query->whereKeyNot($rule->getKey());
        }

        if ($query->count() >= self::$activeRuleLimit) {
            throw ValidationException::withMessages([
                'is_active' => __('i18n.backend.support.aging.aging_rule_validator.this_object_type_has_reached_the_maximum_number_of').self::$activeRuleLimit.').',
            ]);
        }
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
