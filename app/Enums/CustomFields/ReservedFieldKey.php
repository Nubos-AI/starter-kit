<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

enum ReservedFieldKey: string
{
    case Name = 'name';

    public static function isReserved(string $key): bool
    {
        return self::tryFrom($key) !== null;
    }
}
