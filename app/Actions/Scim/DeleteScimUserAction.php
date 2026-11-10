<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Users\DeleteUserAction;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use App\Models\User;
use App\Support\Scim\ScimAccessGuard;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteScimUserAction
{
    public function __construct(
        private readonly DeleteUserAction $deleteUser,
        private readonly ScimAccessGuard $accessGuard,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(ScimToken $token, User $user): void
    {
        $this->accessGuard->assertMaySteer($token, $user);

        try {
            $this->deleteUser->execute($user);
        } catch (SelfLockoutException $exception) {
            throw ScimException::mutability($exception->getMessage());
        }

        Log::info('A user was removed through scim.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);
    }
}
