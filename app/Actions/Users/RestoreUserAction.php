<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class RestoreUserAction
{
    /**
     * @throws Throwable
     */
    public function execute(User $user, UserStatus $status): void
    {
        DB::transaction(function () use ($user, $status): void {
            $user->restore();
            $user->roleAssignments()->delete();
            $user->permissionOverrides()->delete();
            $user->forgetResolvedRoles();

            $user->forceFill(['status' => $status])->save();
        });
    }
}
