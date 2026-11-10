<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use LogicException;

class ReadOnlyRecordFieldException extends LogicException
{
    public function __construct(string $fieldKey)
    {
        parent::__construct(
            "The field \"{$fieldKey}\" cannot be assigned directly. Use updateFields() so validation, "
            .'field permissions, encryption and the optimistic lock still apply.',
        );
    }
}
