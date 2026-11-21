<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\QueryException;
use PDOException;

class WriteAttempt
{
    /**
     * @param  Closure(): mixed  $callback
     */
    public static function reachedTheDatabase(Closure $callback): bool
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $exception->getSql()) !== 1) {
                throw $exception;
            }

            return true;
        } catch (PDOException) {
            return true;
        }

        return false;
    }
}
