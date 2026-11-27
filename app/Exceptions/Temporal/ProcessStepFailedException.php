<?php

declare(strict_types=1);

namespace App\Exceptions\Temporal;

use RuntimeException;
use Throwable;

class ProcessStepFailedException extends RuntimeException
{
    public static function forUpdate(string $workflowId, string $update, Throwable $previous): self
    {
        return new self("The step \"{$update}\" of the process \"{$workflowId}\" failed.", 0, $previous);
    }

    public static function alreadyClosed(string $workflowId, string $update, Throwable $previous): self
    {
        return new self("The process \"{$workflowId}\" is already closed and cannot run the step \"{$update}\".", 0, $previous);
    }
}
