<?php

declare(strict_types=1);

namespace App\DTOs\Scim;

use App\Enums\Scim\ScimPatchOperation;

readonly class ScimPatchOperationData
{
    public function __construct(
        public ScimPatchOperation $operation,
        public ?string $path,
        public mixed $value,
    ) {}
}
