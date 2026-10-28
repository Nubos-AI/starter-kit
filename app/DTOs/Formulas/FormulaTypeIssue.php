<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaTypeIssueCause;
use App\Enums\Formulas\FormulaValueType;

readonly class FormulaTypeIssue
{
    public function __construct(
        public FormulaTypeIssueCause $cause,
        public int $position,
        public ?string $fieldKey = null,
        public ?FormulaFunction $function = null,
        public ?FormulaValueType $expectedType = null,
        public ?FormulaValueType $actualType = null,
    ) {}

    public function message(): string
    {
        $details = collect([
            $this->fieldKey === null ? null : __('i18n.backend.dtos.formulas.formula_type_issue.field', ['field' => $this->fieldKey]),
            $this->function === null ? null : __('i18n.backend.dtos.formulas.formula_type_issue.function', ['function' => $this->function->value]),
            $this->expectedType === null ? null : __('i18n.backend.dtos.formulas.formula_type_issue.expected', ['type' => $this->expectedType->value]),
            $this->actualType === null ? null : __('i18n.backend.dtos.formulas.formula_type_issue.actual', ['type' => $this->actualType->value]),
        ])->filter()->implode(', ');

        return __('i18n.backend.dtos.formulas.formula_type_issue.message', [
            'cause' => $this->cause->label(),
            'position' => $this->position,
            'details' => $details === '' ? '' : __('i18n.backend.dtos.formulas.formula_type_issue.details', ['details' => $details]),
        ]);
    }
}
