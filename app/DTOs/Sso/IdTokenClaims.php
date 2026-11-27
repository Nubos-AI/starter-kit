<?php

declare(strict_types=1);

namespace App\DTOs\Sso;

readonly class IdTokenClaims
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $email,
        public bool $isEmailVerified,
        public ?string $givenName,
        public ?string $familyName,
    ) {}
}
