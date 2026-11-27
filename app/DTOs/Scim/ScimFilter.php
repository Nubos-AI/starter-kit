<?php

declare(strict_types=1);

namespace App\DTOs\Scim;

readonly class ScimFilter
{
    public function __construct(
        public string $attribute,
        public string $value,
    ) {}
}
