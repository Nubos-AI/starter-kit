<?php

declare(strict_types=1);

namespace App\DTOs\Sso;

readonly class ProvisionedUserData
{
    public function __construct(
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
        public bool $isActive,
        public ?string $roleId,
    ) {}
}
