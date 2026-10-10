<?php

declare(strict_types=1);

namespace App\Traits\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesCreatorOrAdmin
{
    private function isCreatorOrAdmin(User $user, Model $resource, ?string $creatorId): bool
    {
        if (!$this->tenantBoundary->admits($user, $resource)) {
            return false;
        }

        return $creatorId === $user->getKey() || $user->isEscalatedAuthority();
    }
}
