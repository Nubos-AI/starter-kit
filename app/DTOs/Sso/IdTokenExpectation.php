<?php

declare(strict_types=1);

namespace App\DTOs\Sso;

readonly class IdTokenExpectation
{
    public function __construct(
        public string $issuer,
        public string $clientId,
        public string $nonce,
    ) {}
}
