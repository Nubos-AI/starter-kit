<?php

declare(strict_types=1);

namespace App\Exceptions\Governance;

use RuntimeException;

class OverlapCheckOutsideTransactionException extends RuntimeException
{
    public static function forUser(string $userId): self
    {
        return new self(
            sprintf(
                'The absence overlap check for user "%s" requires an open transaction; a transaction-scoped advisory lock taken outside one is released immediately.',
                $userId,
            ),
        );
    }
}
