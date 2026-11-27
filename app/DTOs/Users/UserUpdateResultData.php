<?php

declare(strict_types=1);

namespace App\DTOs\Users;

use App\Models\User;

readonly class UserUpdateResultData
{
    public function __construct(
        public User $user,
        public ?string $requestedEmail,
    ) {}
}
