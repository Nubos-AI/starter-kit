<?php

declare(strict_types=1);

namespace App\Exceptions\Recurrence;

use InvalidArgumentException;

class InvalidRecurrenceRuleException extends InvalidArgumentException
{
    public static function forInterval(int $interval, int $maximum): self
    {
        return new self("A recurrence interval must lie between 1 and {$maximum}, {$interval} given.");
    }
}
