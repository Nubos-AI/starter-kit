<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Formulas\StartFormulaBackfillAction;
use App\Enums\CustomFields\FieldType;
use App\Handlers\CustomFields\RelationFieldHandler;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\ComputedDependencyWriter;
use App\Support\Engine\FieldDependencyGraphGuard;
use App\Support\Engine\FieldIndexingRules;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\RollupBackfillStarter;
use App\Support\Engine\RollupDependencyWriter;
use App\Support\Engine\RollupFieldConfigValidator;
use App\Support\Engine\SelectFieldConfigValidator;
use App\Support\Engine\ShadowedRecordFieldKeys;
use App\Support\Engine\SystemObjectTypeGuard;
use App\Support\Formulas\FormulaFieldConfigValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class CreateFieldDefinitionAction
{
    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly FieldDependencyGraphGuard $dependencyGraphGuard,
        private readonly FormulaFieldConfigValidator $formulaConfigValidator,
        private readonly ComputedDependencyWriter $computedDependencyWriter,
        private readonly RollupFieldConfigValidator $rollupConfigValidator,
        private readonly RollupDependencyWriter $rollupDependencyWriter,
        private readonly RollupBackfillStarter $rollupBackfillStarter,
        private readonly RelationFieldHandler $relationFieldHandler,
        private readonly SelectFieldConfigValidator $selectConfigValidator,
        private readonly SystemObjectTypeGuard $systemObjectTypeGuard,
        private readonly FieldIndexingRules $indexingRules,
        private readonly ShadowedRecordFieldKeys $shadowedFieldKeys,
        private readonly StartFormulaBackfillAction $startFormulaBackfillAction,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(array $input): FieldDefinition
    {
        $validated = Validator::make(
            $input,
            [
                'object_type_id' => [
                    'required',
                    'string',
                    Rule::exists('object_types', 'id')->where('tenant_id', (string) TenantContext::currentId()),
                ],
                'field_group_id' => [
                    'nullable',
                    'string',
                    Rule::exists('field_groups', 'id')
                        ->where('object_type_id', $input['object_type_id'] ?? null)
                        ->where('tenant_id', (string) TenantContext::currentId())
                        ->whereNull('deleted_at'),
                ],
                'key' => [
                    'required',
                    'string',
                    'regex:/^[a-z][a-z0-9_]*$/',
                    'max:255',
                    Rule::notIn($this->shadowedFieldKeys->all()),
                ],
                'field_type' => ['required', Rule::enum(FieldType::class)],
                'is_required' => ['boolean'],
                'is_unique' => ['boolean'],
                'is_searchable' => ['boolean'],
                'is_translatable' => ['boolean'],
                'is_encrypted' => ['boolean'],
                'is_sortable' => ['boolean'],
                'is_filterable' => ['boolean'],
                'is_default_column' => ['boolean'],
                ...config('modules.fields.create_rules', []),
                'list_position' => ['nullable', 'integer', 'min:0'],
                'config' => ['nullable', 'array'],
                'validation_rules' => ['nullable', 'array'],
                'default_value' => ['nullable', 'array'],
                'i18n_labels' => ['nullable', 'array'],
                'i18n_descriptions' => ['nullable', 'array'],
            ],
            ['key.not_in' => __('i18n.backend.actions.engine.create_field_definition_action.this_field_key_is_reserved_for_the_record_itself')],
        )->validate();

        $this->systemObjectTypeGuard->assertFieldsAreManageable(
            ObjectType::query()->whereKey((string) $validated['object_type_id'])->firstOrFail(),
        );

        $validated += config('modules.fields.defaults', []);

        $isEncrypted = (bool) ($validated['is_encrypted'] ?? false);

        if (!$isEncrypted) {
            $validated['is_sortable'] ??= true;
            $validated['is_filterable'] ??= true;
            $validated['is_default_column'] ??= true;
        }

        $this->indexingRules->assertEncryptionIsCompatible(
            $isEncrypted,
            (bool) ($validated['is_sortable'] ?? false),
            (bool) ($validated['is_filterable'] ?? false),
            (bool) ($validated['is_unique'] ?? false),
        );

        return DB::transaction(function () use ($validated): FieldDefinition {
            $field = FieldDefinition::query()->create($validated);
            $objectType = $field->objectType()->firstOrFail();

            if ($field->field_type === FieldType::Rollup) {
                $configuration = $this->rollupConfigValidator->validate($field);

                $this->dependencyGraphGuard->guardAcyclic($field);
                $this->rollupDependencyWriter->rewriteEdges($field, $configuration);
                $this->rollupBackfillStarter->backfill($field);
            }

            if ($field->field_type === FieldType::Computed) {
                $formula = $this->formulaConfigValidator->validate($field);

                $this->dependencyGraphGuard->guardAcyclic($field);
                $this->computedDependencyWriter->rewriteEdges($field, $formula);
                $this->startFormulaBackfillAction->execute($field);
            }

            if (in_array($field->field_type, [FieldType::SingleSelect, FieldType::MultiSelect], true)) {
                $this->selectConfigValidator->validate($field);
            }

            if (in_array($field->field_type, [FieldType::RelationHasMany, FieldType::RelationManyToMany], true)) {
                $this->relationFieldHandler->validateConfig($field);
            }

            if ($objectType->isGeneric()) {
                $this->indexRegistry->ensureSortAndFilterIndexes($field);
            }

            return $field;
        });
    }
}
