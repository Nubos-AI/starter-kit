<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Exceptions\Temporal\SealBindingMismatchException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\EncryptException;

class ProcessSeal
{
    /**
     * @var non-empty-string
     */
    private static string $separator = "\n";

    /**
     * @param  array<string, string|int|null>  $binding
     *
     * @throws EncryptException
     */
    public static function seal(Encrypter $encrypter, array $binding, string $plaintext): string
    {
        return $encrypter->encryptString(self::bindingLine($binding).self::$separator.$plaintext);
    }

    /**
     * @param  array<string, string|int|null>  $binding
     *
     * @throws DecryptException
     * @throws SealBindingMismatchException
     */
    public static function unseal(Encrypter $encrypter, array $binding, string $sealed): string
    {
        $content = $encrypter->decryptString($sealed);
        $parts = explode(self::$separator, $content, 2);

        if (count($parts) !== 2 || !hash_equals(self::bindingLine($binding), $parts[0])) {
            throw SealBindingMismatchException::make();
        }

        return $parts[1];
    }

    public static function digest(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * @param  array<string, string|int|null>  $binding
     */
    private static function bindingLine(array $binding): string
    {
        return json_encode($binding, JSON_THROW_ON_ERROR);
    }
}
