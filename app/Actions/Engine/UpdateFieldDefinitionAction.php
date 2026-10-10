<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Formulas\StartFormulaBackfillAction;
use App\Enums\CustomFields\FieldType;
use App\Handlers\CustomFields\RelationFieldHandler;
use App\Models\FieldDefinition;
use App\Support\Engine\ComputedDependencyWriter;
use App\Support\Engine\FieldDependencyGraphGuard;
use App\Support\Engine\FieldIndexingRules;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\RollupBackfillStarter;
use App\Support\Engine\RollupDependencyWriter;
use App\Support\Engine\RollupFieldConfigValidator;
use App\Support\Engine\SelectFieldConfigValidator;
use App\Support\Engine\SystemObjectTypeGuard;
use App\Support\Formulas\FormulaFieldConfigValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateFieldDefinitionAction
{
    /** @var list<string> */
    private array $indexRelevantKeys = [
        'is_sortable',
        'is_filterable',
        'is_searchable',
        'is_encrypted',
        'is_translatable',
    ];

    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly FieldDependencyGraphGuard $dependencyGraphGuard,
        private readonly FormulaFieldConfigValidator $formulaConfigValidator,
        private readonly ComputedDependencyWriter $computedDependencyWriter,
        private readonly StartFormulaBackfillAction $startFormulaBackfillAction,
        private readonly RollupFieldConfigValidator $rollupConfigValidator,
        private readonly RollupDependencyWriter $rollupDependencyWriter,
        private readonly RollupBackfillStarter $rollupBackfillStarter,
        private readonly RelationFieldHandler $relationFieldHandler,
        private readonly SelectFieldConfigValidator $selectConfigValidator,
        private readonly SystemObjectTypeGuard $systemObjectTypeGuard,
        private readonly FieldIndexingRules $indexingRules,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(FieldDefinition $field, array $input): FieldDefinition
    {
        $this->systemObjectTypeGuard->assertFieldIsManageable($field);

        $validated = Validator::make(
            $input,
            [
                'field_group_id' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('field_groups', 'id')
                        ->where('object_type_id', $field->object_type_id)
                        ->where('tenant_id', (string) TenantContext::currentId())
                        ->whereNull('deleted_at'),
                ],
                'is_required' => ['sometimes', 'boolean'],
                'is_unique' => ['sometimes', 'boolean'],
                'is_searchable' => ['sometimes', 'boolean'],
                'is_translatable' => ['sometimes', 'boolean'],
                'is_encrypted' => ['sometimes', 'boolean'],
                'is_sortable' => ['sometimes', 'boolean'],
                'is_filterable' => ['sometimes', 'boolean'],
                'is_default_column' => ['sometimes', 'boolean'],
                ...config('modules.fields.update_rules', []),
                'list_position' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'config' => ['sometimes', 'array'],
                'validation_rules' => ['sometimes', 'nullable', 'array'],
                'default_value' => ['sometimes', 'nullable', 'array'],
                'i18n_labels' => ['sometimes', 'nullable', 'array'],
                'i18n_descriptions' => ['sometimes', 'nullable', 'array'],
            ]
        )->validate();

        $this->indexingRules->assertEncryptionIsCompatible(
            (bool) ($validated['is_encrypted'] ?? $field->is_encrypted),
            (bool) ($validated['is_sortable'] ?? $field->is_sortable),
            (bool) ($validated['is_filterable'] ?? $field->is_filterable),
            (bool) ($validated['is_unique'] ?? $field->is_unique),
        );

        $changesConfiguration = array_key_exists('config', $validated);
        $originalConfig = $field->config ?? [];
        $originalFormula = $changesConfiguration ? $this->formulaOf($field) : null;

        if ($changesConfiguration) {
            $this->assertConfigurationIsSupported($field->field_type);
        }

        $changesIndexes = array_intersect($this->indexRelevantKeys, array_keys($validated)) !== [];

        return DB::transaction(function () use (
            $field,
            $validated,
            $changesConfiguration,
            $changesIndexes,
            $originalConfig,
            $originalFormula,
        ): FieldDefinition {
            $field->update($validated);

            if ($changesConfiguration) {
                $this->applyConfiguration($field, $originalConfig, $originalFormula);
            }

            if ($changesIndexes && $field->objectType()->firstOrFail()->isGeneric()) {
                $this->indexRegistry->syncSortAndFilterIndexes($field);
            }

            return $field;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertConfigurationIsSupported(FieldType $fieldType): void
    {
        $supported = [
            FieldType::Computed,
            FieldType::Rollup,
            FieldType::SingleSelect,
            FieldType::MultiSelect,
            FieldType::RelationHasMany,
            FieldType::RelationManyToMany,
        ];

        if (!in_array($fieldType, $supported, true)) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.actions.engine.update_field_definition_action.only_calculated_rollup_selection_and_relationship_fields_have_a'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $originalConfig
     *
     * @throws Throwable
     */
    private function applyConfiguration(FieldDefinition $field, array $originalConfig, ?string $originalFormula): void
    {
        if ($field->field_type === FieldType::Rollup) {
            $configuration = $this->rollupConfigValidator->validate($field);

            $this->dependencyGraphGuard->guardAcyclic($field);
            $this->rollupDependencyWriter->rewriteEdges($field, $configuration);
            $this->rollupBackfillStarter->backfill($field);

            return;
        }

        if ($field->field_type === FieldType::Computed) {
            $formula = $this->formulaConfigValidator->validate($field);

            $this->dependencyGraphGuard->guardAcyclic($field);
            $this->computedDependencyWriter->rewriteEdges($field, $formula);

            if ($this->formulaOf($field) !== $originalFormula) {
                $this->startFormulaBackfillAction->execute($field);
            }

            return;
        }

        if (in_array($field->field_type, [FieldType::RelationHasMany, FieldType::RelationManyToMany], true)) {
            $this->relationFieldHandler->validateConfig($field);

            return;
        }

        $this->selectConfigValidator->validate($field, $originalConfig);
    }

    private function formulaOf(FieldDefinition $field): ?string
    {
        $formula = $field->config['formula'] ?? null;

        return is_string($formula) ? $formula : null;
    }
}
