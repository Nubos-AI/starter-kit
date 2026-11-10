<?php

declare(strict_types=1);

namespace App\Exceptions\Temporal;

use RuntimeException;

class PeriodicScanFailedException extends RuntimeException
{
    public static function for(string $signature, int $exitCode): self
    {
        return new self("Periodic scan [{$signature}] exited with code {$exitCode}.");
    }
}
