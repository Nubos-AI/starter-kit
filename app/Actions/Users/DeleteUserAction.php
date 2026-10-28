<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteUserAction
{
    public function __construct(private readonly SelfLockoutGuard $guard) {}

    /**
     * @throws Throwable
     */
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->guard->assertUserIsNotLastEscalatedHolder($user);

            $user->forceFill(['status' => UserStatus::Deleted])->save();
            $user->delete();
        });
    }
}
