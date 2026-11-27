<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use InvalidArgumentException;

class ProcessId
{
    private static string $allowedPart = '/\A[A-Za-z0-9._@-]+\z/';

    private static string $globalScope = 'system';

    /**
     * @return non-empty-string
     */
    public static function forTenant(string $process, string $tenantId, string ...$keys): string
    {
        return self::compose($process, $tenantId, array_values($keys));
    }

    /**
     * @return non-empty-string
     */
    public static function global(string $process, string ...$keys): string
    {
        return self::compose($process, self::$globalScope, array_values($keys));
    }

    /**
     * @param  list<string>  $keys
     * @return non-empty-string
     */
    private static function compose(string $process, string $scope, array $keys): string
    {
        foreach ([$process, $scope, ...$keys] as $part) {
            if (preg_match(self::$allowedPart, $part) !== 1) {
                throw new InvalidArgumentException("The workflow id part \"{$part}\" contains characters outside [A-Za-z0-9._@-].");
            }
        }

        $suffix = $keys === [] ? '' : ':'.implode(':', $keys);

        return "{$process}:{$scope}{$suffix}";
    }
}
