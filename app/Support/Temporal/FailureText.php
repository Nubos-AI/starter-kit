<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use Temporal\Exception\Failure\ApplicationFailure;
use Throwable;

class FailureText
{
    public static function of(Throwable $failure): string
    {
        $databaseFailure = self::databaseFailureIn($failure);

        if ($databaseFailure instanceof Throwable) {
            return 'SQLSTATE['.self::sqlStateOf($databaseFailure).']';
        }

        if ($failure instanceof ApplicationFailure) {
            return $failure->getOriginalMessage();
        }

        return Str::of($failure->getMessage())->before("\n")->trim()->value();
    }

    private static function databaseFailureIn(Throwable $failure): ?Throwable
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                return $cause;
            }
        }

        return null;
    }

    private static function sqlStateOf(Throwable $failure): string
    {
        $code = (string) $failure->getCode();

        return preg_match('/\A[0-9A-Z]{5}\z/', $code) === 1 ? $code : 'unknown';
    }
}
