<?php

declare(strict_types=1);

namespace App\Policies\Sso;

use App\Models\User;

class SsoConnectionPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission('sso.manage');
    }
}
