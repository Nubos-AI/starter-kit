<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Support\Arr;

class IdentifierList
{
    /**
     * @return list<string>
     */
    public static function from(mixed $value): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $identifier): string => (string) $identifier,
            Arr::wrap($value),
        )));
    }
}
