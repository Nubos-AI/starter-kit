<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\CrossOperator;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class RecordValidator
{
    public function __construct(
        private readonly FieldTypeRegistry $registry,
        private readonly TranslatableValueResolver $labels,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     *
     * @throws ValidationException
     */
    public function validate(ObjectType $objectType, ?array $data, string $tenantId, ?CustomRecord $ignore = null): void
    {
        $fields = $objectType->loadMissing('fieldDefinitions')->fieldDefinitions;

        if ($fields->isEmpty()) {
            return;
        }

        $rules = [];
        $attributes = [];

        /** @var array<int, array{path: string, rules: array<int, mixed>, condition: callable(mixed): bool}> $conditional */
        $conditional = [];

        foreach ($fields as $field) {
            $path = 'data.'.$field->key;
            $attributes[$path] = $this->attributeName($field);

            [$topLevel, $subRules, $crossConditional] = $this->assembleFieldRules($field, $tenantId, $ignore);
            $rules[$path] = $topLevel;

            foreach ($subRules as $subPath => $subRule) {
                $conditional[] = [
                    'path' => $subPath,
                    'rules' => $subRule,
                    'condition' => static fn (mixed $input): bool => data_get($input, $path) !== null,
                ];
            }

            foreach ($crossConditional as $entry) {
                $reference = $entry['reference'];
                $conditional[] = [
                    'path' => $entry['path'],
                    'rules' => $entry['rules'],
                    'condition' => static fn (mixed $input): bool => filled(data_get($input, $reference)),
                ];
            }
        }

        $validator = Validator::make(['data' => $data ?? []], $rules, [], $attributes);

        foreach ($conditional as $entry) {
            $validator->sometimes($entry['path'], $entry['rules'], $entry['condition']);
        }

        $validator->validate();
    }

    /**
     * @return array{0: array<int, mixed>, 1: array<string, array<int, mixed>>, 2: array<int, array{path: string, rules: array<int, mixed>, reference: string}>}
     */
    private function assembleFieldRules(FieldDefinition $field, string $tenantId, ?CustomRecord $ignore): array
    {
        $path = 'data.'.$field->key;
        $handlerRules = $this->registry->handlerFor($field->field_type)->validationRules($field);

        $topLevel = [];
        $subRules = [];

        foreach ($handlerRules as $ruleKey => $ruleValue) {
            if (is_int($ruleKey)) {
                if ($ruleValue === 'nullable' || $ruleValue === 'required') {
                    continue;
                }

                $topLevel[] = $ruleValue;

                continue;
            }

            if ($ruleKey === '*') {
                $subRules[$path.'.*'] = (array) $ruleValue;

                continue;
            }

            $subRules[$path.'.'.$ruleKey] = (array) $ruleValue;
        }

        array_unshift($topLevel, $field->is_required ? 'required' : 'nullable');

        [$definitionRules, $crossPairs] = $this->definitionRules($field);

        foreach ($definitionRules as $rule) {
            $topLevel[] = $rule;
        }

        if ($this->wantsUnique($field)) {
            $topLevel[] = $this->uniqueRule($field, $tenantId, $ignore);
        }

        $crossConditional = [];

        foreach ($crossPairs as $pair) {
            $crossConditional[] = [
                'path' => $path,
                'rules' => [$pair['rule']],
                'reference' => $pair['reference'],
            ];
        }

        return [$topLevel, $subRules, $crossConditional];
    }

    /**
     * @return array{0: list<mixed>, 1: array<int, array{reference: string, rule: string}>}
     */
    private function definitionRules(FieldDefinition $field): array
    {
        $config = $field->validation_rules;

        if (!is_array($config)) {
            return [[], []];
        }

        $rules = [];
        $crossConditional = [];
        $isDate = in_array($field->field_type, [FieldType::Date, FieldType::DateTime], true);

        if (isset($config['regex']) && is_string($config['regex']) && $config['regex'] !== '') {
            $pattern = $config['regex'];

            if (strlen($pattern) <= (int) config('engine.validation.max_regex_length') && @preg_match($pattern, '') !== false) {
                $rules[] = 'regex:'.$pattern;
            }
        }

        if (array_key_exists('min', $config)) {
            $rule = $this->boundRule($config['min'], $isDate, 'min', 'after_or_equal');

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        if (array_key_exists('max', $config)) {
            $rule = $this->boundRule($config['max'], $isDate, 'max', 'before_or_equal');

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        if (isset($config['in']) && is_array($config['in'])) {
            $rules[] = Rule::in(array_values($config['in']));
        }

        if (isset($config['cross']) && is_array($config['cross'])) {
            foreach ($config['cross'] as $operator => $target) {
                $crossOperator = is_string($operator) ? CrossOperator::tryFrom($operator) : null;

                if ($crossOperator !== null
                    && is_string($target)
                    && $target !== ''
                ) {
                    $rule = $crossOperator->value.':data.'.$target;

                    if ($crossOperator->isNumeric()) {
                        $crossConditional[] = ['reference' => 'data.'.$target, 'rule' => $rule];

                        continue;
                    }

                    $rules[] = $rule;
                }
            }
        }

        return [$rules, $crossConditional];
    }

    private function boundRule(mixed $value, bool $isDate, string $numericRule, string $dateRule): ?string
    {
        if ($isDate && is_string($value) && $value !== '') {
            return $dateRule.':'.$value;
        }

        if (!$isDate && is_numeric($value)) {
            return $numericRule.':'.$value;
        }

        return null;
    }

    private function wantsUnique(FieldDefinition $field): bool
    {
        return $field->is_unique && !$field->is_encrypted && !$field->is_translatable;
    }

    private function uniqueRule(FieldDefinition $field, string $tenantId, ?CustomRecord $ignore): Unique
    {
        $rule = Rule::unique('custom_records', 'data->'.$field->key)
            ->where(static function (Builder $query) use ($field, $tenantId): void {
                $query->where('tenant_id', $tenantId)
                    ->where('object_type_id', $field->object_type_id)
                    ->whereNull('deleted_at');
            });

        if ($ignore !== null) {
            $rule->ignore($ignore->getKey(), 'id');
        }

        return $rule;
    }

    private function attributeName(FieldDefinition $field): string
    {
        $label = $this->labels->resolve($field->i18n_labels);

        return is_string($label) && $label !== '' ? $label : $field->key;
    }
}
