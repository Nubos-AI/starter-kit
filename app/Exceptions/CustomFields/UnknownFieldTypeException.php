<?php

declare(strict_types=1);

namespace App\Exceptions\CustomFields;

use App\Enums\CustomFields\FieldType;
use RuntimeException;

class UnknownFieldTypeException extends RuntimeException
{
    public function __construct(public readonly FieldType $fieldType)
    {
        parent::__construct(
            "No field handler is registered for field type \"{$fieldType->value}\".",
        );
    }
}
