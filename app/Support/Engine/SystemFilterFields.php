<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\SystemFilterField;
use App\Models\FieldDefinition;
use Illuminate\Support\Collection;

class SystemFilterFields
{
    /**
     * @param  list<array{value: string, label: string}>  $ownerOptions
     * @param  list<array{value: string, label: string}>  $teamOptions
     * @return Collection<int, FieldDefinition>
     */
    public function presented(string $objectTypeId, array $ownerOptions = [], array $teamOptions = []): Collection
    {
        return $this->all($objectTypeId, $ownerOptions, $teamOptions);
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $ownerOptions
     * @param  array<int, array{value: string, label: string}>  $teamOptions
     * @return Collection<int, FieldDefinition>
     */
    public function all(
        string $objectTypeId,
        array $ownerOptions = [],
        array $teamOptions = [],
    ): Collection {
        return new Collection([
            $this->make($objectTypeId, SystemFilterField::Owner->value, __('i18n.backend.support.engine.system_filter_fields.owner'), FieldType::SingleSelect, ['options' => $ownerOptions]),
            $this->make($objectTypeId, SystemFilterField::Team->value, __('i18n.backend.support.engine.system_filter_fields.team'), FieldType::SingleSelect, ['options' => $teamOptions]),
        ]);
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function agingFields(string $objectTypeId): Collection
    {
        return new Collection([
            $this->make($objectTypeId, SystemFilterField::AgingAge->value, __('i18n.backend.support.engine.system_filter_fields.age'), FieldType::Number, []),
            $this->make($objectTypeId, SystemFilterField::AgingStage->value, __('i18n.backend.support.engine.system_filter_fields.level'), FieldType::Number, []),
        ]);
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function payload(Collection $fields): array
    {
        return $fields
            ->map(fn (FieldDefinition $field): array => [
                'key' => $field->key,
                'field_type' => $field->field_type->value,
                'label' => $this->label($field),
                'is_required' => false,
                'is_sortable' => true,
                'is_filterable' => true,
                'is_default_column' => false,
                'list_position' => null,
                'config' => $field->config,
                'validation_rules' => null,
                'default_value' => null,
            ])
            ->values()
            ->all();
    }

    public function isSystemField(FieldDefinition $field): bool
    {
        return !$field->exists && $this->columnFor($field->key) !== null;
    }

    public function isAgingField(string $key): bool
    {
        return match (SystemFilterField::tryFrom($key)) {
            SystemFilterField::AgingAge, SystemFilterField::AgingStage => true,
            default => false,
        };
    }

    /**
     * @return literal-string|null
     */
    public function columnFor(string $key): ?string
    {
        return match (SystemFilterField::tryFrom($key)) {
            SystemFilterField::Owner => 'owner_id',
            SystemFilterField::Team => 'team_id',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function make(string $objectTypeId, string $key, string $label, FieldType $fieldType, array $config): FieldDefinition
    {
        $field = new FieldDefinition;
        $field->object_type_id = $objectTypeId;
        $field->key = $key;
        $field->field_type = $fieldType;
        $field->i18n_labels = [$this->fallbackLocale() => $label];
        $field->is_required = false;
        $field->is_unique = false;
        $field->is_searchable = false;
        $field->is_translatable = false;
        $field->is_encrypted = false;
        $field->is_sortable = true;
        $field->is_filterable = true;
        $field->is_default_column = false;
        $field->config = $config;

        return $field;
    }

    private function label(FieldDefinition $field): string
    {
        $labels = $field->i18n_labels ?? [];
        $label = $labels[$this->fallbackLocale()] ?? null;

        return is_string($label) && $label !== '' ? $label : $field->key;
    }

    private function fallbackLocale(): string
    {
        $fallback = config('app.fallback_locale');

        return is_string($fallback) ? $fallback : 'en';
    }
}
