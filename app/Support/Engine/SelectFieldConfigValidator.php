<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SelectFieldConfigValidator
{
    /**
     * @param  array<string, mixed>|null  $previousConfig
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function validate(FieldDefinition $field, ?array $previousConfig = null): array
    {
        $config = $field->config ?? [];
        $lookup = $config['lookup_object_type'] ?? null;
        $hasLookup = is_string($lookup) && $lookup !== '';
        $hasOptions = array_key_exists('options', $config);

        if ($hasLookup && $hasOptions) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.select_field_config_validator.a_selection_field_uses_either_custom_options_or_a'),
            ]);
        }

        if ($hasLookup) {
            return [];
        }

        $options = $this->readOptions($config);

        if ($previousConfig !== null) {
            $this->assertRemovedOptionsAreUnused($field, $this->readStoredOptions($previousConfig), $options);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function readOptions(array $config): array
    {
        $raw = $config['options'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.select_field_config_validator.a_selection_field_requires_at_least_one_option'),
            ]);
        }

        $options = [];

        foreach (array_values($raw) as $option) {
            if (!is_string($option) || trim($option) === '') {
                throw ValidationException::withMessages([
                    'config' => __('i18n.backend.support.engine.select_field_config_validator.each_selection_option_requires_a_label'),
                ]);
            }

            $options[] = $option;
        }

        if (count(array_unique($options)) !== count($options)) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.select_field_config_validator.selection_options_must_be_unique'),
            ]);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function readStoredOptions(array $config): array
    {
        $raw = $config['options'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn (mixed $option): bool => is_string($option)));
    }

    /**
     * @param  list<string>  $previous
     * @param  list<string>  $next
     *
     * @throws ValidationException
     */
    private function assertRemovedOptionsAreUnused(FieldDefinition $field, array $previous, array $next): void
    {
        $removed = array_values(array_diff($previous, $next));

        if ($removed === []) {
            return;
        }

        foreach ($removed as $option) {
            $usage = $this->usageCount($field, $option);

            if ($usage > 0) {
                throw ValidationException::withMessages([
                    'config' => sprintf(
                        __('i18n.backend.support.engine.select_field_config_validator.the_option_s_is_still_used_by_d_record'),
                        $option,
                        $usage,
                    ),
                ]);
            }
        }
    }

    private function usageCount(FieldDefinition $field, string $option): int
    {
        $query = CustomRecord::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->ofType($field->object_type_id);

        if ($field->field_type === FieldType::MultiSelect) {
            $query->whereJsonContains('data->'.$field->key, $option);

            return $query->count();
        }

        $query->where(fn (Builder $builder): Builder => $builder
            ->whereField($field->key, $option)
            ->orWhereJsonContains('data->'.$field->key, $option),
        );

        return $query->count();
    }
}
