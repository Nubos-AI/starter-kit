<?php

declare(strict_types=1);

namespace App\Exceptions\Modules;

use RuntimeException;

class OutboundBlockedException extends RuntimeException
{
    public function __construct(public readonly string $target, ?string $message = null)
    {
        parent::__construct($message ?? "Outbound delivery to [{$target}] is blocked by the active context.");
    }
}
