<?php

declare(strict_types=1);

namespace App\Traits;

trait UsesTenantConnection
{
    public function getConnectionName(): string
    {
        return 'tenant';
    }
}
