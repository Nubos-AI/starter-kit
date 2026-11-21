<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use RuntimeException;

class IdTokenRejectedException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $isUnknownKey = false)
    {
        parent::__construct($message);
    }

    public static function malformed(): self
    {
        return new self('The id token is malformed.');
    }

    public static function algorithmNotAccepted(): self
    {
        return new self('The id token uses an algorithm that is not accepted.');
    }

    public static function unknownKey(): self
    {
        return new self('No published key matches the id token.', true);
    }

    public static function invalidSignature(): self
    {
        return new self('The signature of the id token is invalid.');
    }

    public static function foreignIssuer(): self
    {
        return new self('The issuer of the id token does not match.');
    }

    public static function foreignAudience(): self
    {
        return new self('The id token was not issued for this client.');
    }

    public static function foreignAuthorizedParty(): self
    {
        return new self('The authorized party of the id token does not match.');
    }

    public static function expired(): self
    {
        return new self('The id token has expired.');
    }

    public static function issuedInTheFuture(): self
    {
        return new self('The id token was issued in the future.');
    }

    public static function foreignNonce(): self
    {
        return new self('The nonce of the id token does not match.');
    }

    public static function missingSubject(): self
    {
        return new self('The id token names no subject.');
    }
}
