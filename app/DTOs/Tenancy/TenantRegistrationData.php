<?php

declare(strict_types=1);

namespace App\DTOs\Tenancy;

use App\Enums\Users\Salutation;

readonly class TenantRegistrationData
{
    public function __construct(
        public string $companyName,
        public Salutation $salutation,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $password,
    ) {}
}
