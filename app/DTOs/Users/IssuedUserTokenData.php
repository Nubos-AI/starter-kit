<?php

declare(strict_types=1);

namespace App\DTOs\Users;

use Carbon\CarbonInterface;

readonly class IssuedUserTokenData
{
    public function __construct(
        public string $token,
        public CarbonInterface $expiresAt,
    ) {}
}
