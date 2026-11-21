<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use RuntimeException;

/**
 * @implements CastsAttributes<mixed, mixed>
 */
class EncryptedJsonValue implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException("Encrypted custom-field value for \"{$key}\" must be a string.");
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (DecryptException) {
            throw new RuntimeException(
                "Custom-field value for \"{$key}\" could not be decrypted (wrong or rotated APP_KEY?).",
            );
        }

        return json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
