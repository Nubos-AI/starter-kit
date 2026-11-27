<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Illuminate\Support\Str;

class ProcessUpdateId
{
    private static string $separator = '.';

    private static string $fingerprintPattern = '/\A[0-9a-f]{64}\z/';

    /**
     * @return non-empty-string
     */
    public static function for(string $fingerprint): string
    {
        return $fingerprint.self::$separator.Str::ulid();
    }

    public static function fingerprintOf(?string $updateId): ?string
    {
        if ($updateId === null || !str_contains($updateId, self::$separator)) {
            return null;
        }

        $fingerprint = Str::before($updateId, self::$separator);

        return preg_match(self::$fingerprintPattern, $fingerprint) === 1 ? $fingerprint : null;
    }
}
