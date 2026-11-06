<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use Illuminate\Database\QueryException;

trait TranslatesUniqueViolations
{
    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->getCode() === '23505')
            || str_contains($exception->getMessage(), 'Unique violation');
    }
}
