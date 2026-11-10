<?php

declare(strict_types=1);

namespace App\Exceptions\Links;

use RuntimeException;

class UnknownSourceLinkException extends RuntimeException
{
    public function __construct(public readonly string $sourceType)
    {
        parent::__construct("No source link resolver is registered for source type \"{$sourceType}\".");
    }
}
