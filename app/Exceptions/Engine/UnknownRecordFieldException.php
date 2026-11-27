<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class UnknownRecordFieldException extends RuntimeException
{
    public function __construct(string $fieldKey, string $objectTypeId)
    {
        parent::__construct(
            "The object type \"{$objectTypeId}\" declares no field \"{$fieldKey}\".",
        );
    }
}
