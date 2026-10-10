<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\Enums\Formulas\FormulaFunction;
use App\Exceptions\Formulas\FormulaTypeException;
use App\Support\Abstracts\ConfigDrivenRegistry;

/**
 * @extends ConfigDrivenRegistry<FormulaFunctionHandler>
 */
class FormulaFunctionRegistry extends ConfigDrivenRegistry
{
    public function hasHandler(FormulaFunction $function): bool
    {
        return $this->entryClass($function->value) !== null;
    }

    /**
     * @throws FormulaTypeException
     */
    public function handlerFor(FormulaFunction $function): FormulaFunctionHandler
    {
        return $this->resolve(
            $this->entryClass($function->value) ?? throw FormulaTypeException::missingHandler($function),
        );
    }

    protected function configKey(): string
    {
        return 'formulas.function_handlers';
    }

    /**
     * @return class-string<FormulaFunctionHandler>
     */
    protected function contract(): string
    {
        return FormulaFunctionHandler::class;
    }
}
