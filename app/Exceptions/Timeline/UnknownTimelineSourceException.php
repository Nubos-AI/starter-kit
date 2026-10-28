<?php

declare(strict_types=1);

namespace App\Exceptions\Timeline;

use RuntimeException;

class UnknownTimelineSourceException extends RuntimeException
{
    public function __construct(public readonly string $sourceKey)
    {
        parent::__construct("No timeline source is registered for source key \"{$sourceKey}\".");
    }
}
