<?php

declare(strict_types=1);

namespace App\Exceptions\Formulas;

use App\Enums\Formulas\FormulaFunction;
use RuntimeException;

class FormulaTypeException extends RuntimeException
{
    public function __construct(string $message, public readonly ?FormulaFunction $formulaFunction = null)
    {
        parent::__construct($message);
    }

    public static function missingHandler(FormulaFunction $function): self
    {
        return new self(
            "No formula function handler is registered for function \"{$function->value}\".",
            $function,
        );
    }

    public static function invalidSignature(string $reason): self
    {
        return new self("Invalid formula function signature: {$reason}");
    }
}
