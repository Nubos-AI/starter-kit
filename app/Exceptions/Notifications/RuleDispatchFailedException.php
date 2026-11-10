<?php

declare(strict_types=1);

namespace App\Exceptions\Notifications;

use RuntimeException;
use Throwable;

class RuleDispatchFailedException extends RuntimeException
{
    public static function for(string $ruleId, string $recordId, int $failedRecipients, Throwable $previous): self
    {
        return new self("Notification rule [{$ruleId}] could not reach {$failedRecipients} recipient(s) of record [{$recordId}].", 0, $previous);
    }
}
