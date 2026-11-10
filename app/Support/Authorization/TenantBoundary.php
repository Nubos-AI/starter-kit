<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TenantBoundary
{
    public function admits(User $user, Model $resource): bool
    {
        return $user->tenant_id !== null && $resource->getAttribute('tenant_id') === $user->tenant_id;
    }
}
