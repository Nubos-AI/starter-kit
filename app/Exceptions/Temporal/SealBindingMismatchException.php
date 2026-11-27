<?php

declare(strict_types=1);

namespace App\Exceptions\Temporal;

use RuntimeException;

class SealBindingMismatchException extends RuntimeException
{
    public static function make(): self
    {
        return new self('The sealed process input belongs to another tenant, user or delivery.');
    }
}
