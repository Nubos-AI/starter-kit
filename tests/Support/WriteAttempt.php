<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use PDOException;

class WriteAttempt
{
    /**
     * A code path that survives every guard ends at the database, which this suite
     * has severed: the transaction cannot be opened and the driver throws. Reaching
     * that point proves the guards let the call through without writing anything.
     *
     * @param  Closure(): mixed  $callback
     */
    public static function reachedTheDatabase(Closure $callback): bool
    {
        try {
            $callback();
        } catch (PDOException) {
            return true;
        }

        return false;
    }
}
