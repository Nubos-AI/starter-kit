<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Users\DeleteUserAction;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Exceptions\Scim\ScimException;
use App\Models\User;
use App\Support\Users\UserSessionInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteScimUserAction
{
    public function __construct(
        private readonly DeleteUserAction $deleteUser,
        private readonly UserSessionInvalidator $sessions,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(User $user): void
    {
        try {
            DB::transaction(function () use ($user): void {
                $this->deleteUser->execute($user);
                $this->sessions->invalidate($user);
                $user->tokens()->delete();
            });
        } catch (SelfLockoutException $exception) {
            throw ScimException::mutability($exception->getMessage());
        }

        Log::info('A user was removed through scim.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);
    }
}
