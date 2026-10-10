<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Authorization\SelfLockoutGuard;
use App\Support\Users\UserSessionInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SetUserStatusAction
{
    public function __construct(
        private readonly SelfLockoutGuard $guard,
        private readonly UserSessionInvalidator $sessionInvalidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(User $user, array $data): void
    {
        $validated = Validator::make(
            $data,
            [
                'status' => [
                    'required',
                    Rule::in([UserStatus::Accepted->value, UserStatus::Blocked->value]),
                ],
            ]
        )->validate();

        $status = UserStatus::from((string) $validated['status']);

        DB::transaction(function () use ($user, $status): void {
            if ($status === UserStatus::Blocked) {
                $this->guard->assertUserIsNotLastEscalatedHolder($user);
            }

            $user->forceFill(['status' => $status])->save();

            if ($status === UserStatus::Blocked) {
                $this->sessionInvalidator->invalidate($user);
                $user->tokens()->delete();
            }
        });
    }
}
