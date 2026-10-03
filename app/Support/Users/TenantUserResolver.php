<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

class TenantUserResolver
{
    public function resolve(mixed $actingUser, string $userId): User
    {
        if (!$actingUser instanceof User) {
            abort(403);
        }

        return User::query()
            ->where('tenant_id', $actingUser->tenant_id)
            ->whereKey($userId)
            ->firstOrFail();
    }

    public function resolveAuthorized(mixed $actingUser, string $userId, string $ability): User
    {
        $target = $this->resolve($actingUser, $userId);

        Gate::forUser($actingUser)->authorize($ability, $target);

        return $target;
    }
}
