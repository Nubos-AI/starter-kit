<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Formulas\StartFormulaBackfillAction;
use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\FormulaBackfillRun;
use App\Support\Engine\RollupBackfillStarter;
use Illuminate\Validation\ValidationException;

class RecomputeFieldAction
{
    public function __construct(
        private readonly StartFormulaBackfillAction $startFormulaBackfillAction,
        private readonly RollupBackfillStarter $rollupBackfillStarter,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(FieldDefinition $field): ?FormulaBackfillRun
    {
        if ($field->field_type === FieldType::Computed) {
            return $this->startFormulaBackfillAction->execute($field);
        }

        if ($field->field_type === FieldType::Rollup) {
            $this->rollupBackfillStarter->backfill($field);

            return null;
        }

        throw ValidationException::withMessages([
            'field' => __('i18n.backend.actions.engine.recompute_field_action.only_formula_and_rollup_fields_can_be_recalculated'),
        ]);
    }
}
