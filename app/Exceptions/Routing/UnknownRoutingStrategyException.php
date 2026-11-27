<?php

declare(strict_types=1);

namespace App\Exceptions\Routing;

use RuntimeException;

class UnknownRoutingStrategyException extends RuntimeException
{
    public function __construct(string $strategy)
    {
        parent::__construct("No routing strategy handler is registered for \"{$strategy}\".");
    }
}
