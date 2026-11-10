<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class AmbiguousRecordTypeException extends RuntimeException
{
    public function __construct(string $subject)
    {
        parent::__construct(
            "\"{$subject}\" cannot be resolved because the query is not scoped to a single object type. "
            .'Add ofType() before it.',
        );
    }
}
