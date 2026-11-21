<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Users\UserSessionInvalidator;
use Illuminate\Support\Facades\DB;
use Throwable;

class RestoreUserAction
{
    public function __construct(private readonly UserSessionInvalidator $sessionInvalidator) {}

    /**
     * @throws Throwable
     */
    public function execute(User $user, UserStatus $status): void
    {
        DB::transaction(function () use ($user, $status): void {
            $user->restore();
            $user->roleAssignments()->delete();
            $user->permissionOverrides()->delete();
            $user->teams()->detach();
            $user->forgetResolvedRoles();

            $this->wipeCredentials($user);

            $user->forceFill(['status' => $status])->save();
        });
    }

    private function wipeCredentials(User $user): void
    {
        $this->sessionInvalidator->invalidate($user);

        $user->passkeys()->delete();
        $user->pushSubscriptions()->delete();

        UserIdentity::withoutTenantScope()
            ->where('user_id', $user->getKey())
            ->delete();

        $user->forceFill([
            'password' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
        ]);
    }
}
