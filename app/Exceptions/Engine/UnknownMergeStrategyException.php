<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class UnknownMergeStrategyException extends RuntimeException
{
    public function __construct(string $strategy)
    {
        parent::__construct("No merge strategy handler is registered for \"{$strategy}\".");
    }
}
