<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;
use Throwable;

class IndexCreationFailedException extends RuntimeException
{
    public static function for(string $indexName, Throwable $previous): self
    {
        return new self("The records index \"{$indexName}\" could not be created.", 0, $previous);
    }
}
