<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class UntenantedObjectTypeBackingException extends RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "The model \"{$modelClass}\" cannot back an object type because it is not tenant-owned. "
            .'Scope it with #[ScopedBy([TenantScope::class])] or use the BelongsToTenant trait.',
        );
    }
}
