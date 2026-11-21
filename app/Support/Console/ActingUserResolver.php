<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

class ActingUserResolver
{
    /**
     * @throws MissingActingUserException
     */
    public function resolve(?string $identifier): User
    {
        if ($identifier === null || $identifier === '') {
            throw new MissingActingUserException('The --as-user option is required.', 'acting-user-missing');
        }

        $user = $this->find($identifier);

        if ($user->trashed()) {
            throw new MissingActingUserException('The acting user given in --as-user is deleted.', 'acting-user-trashed');
        }

        if ($user->tenant_id === null) {
            throw new MissingActingUserException('The acting user given in --as-user belongs to no tenant.', 'acting-user-without-tenant');
        }

        if (!Tenant::query()->whereKey($user->tenant_id)->exists()) {
            throw new MissingActingUserException('The tenant of the acting user given in --as-user no longer exists.', 'acting-user-tenant-missing');
        }

        if ($user->is_service) {
            throw new MissingActingUserException('The acting user given in --as-user is a service account and must not act as a user.', 'acting-user-is-service');
        }

        if (!$user->status->canAuthenticate()) {
            throw new MissingActingUserException('The acting user given in --as-user has not accepted the invitation or is suspended.', 'acting-user-not-accepted');
        }

        return $user;
    }

    /**
     * @throws MissingActingUserException
     */
    private function find(string $identifier): User
    {
        $isUlid = Str::isUlid($identifier);

        if (!$isUlid && filter_var($identifier, FILTER_VALIDATE_EMAIL) === false) {
            throw new MissingActingUserException('The --as-user option expects an e-mail address or a ULID.', 'acting-user-malformed');
        }

        $query = User::query()->withTrashed();

        $user = $isUlid
            ? $query->whereKey($identifier)->first()
            : $query->where('email', $identifier)->first();

        if (!$user instanceof User) {
            throw new MissingActingUserException('The acting user given in --as-user is unknown.', 'acting-user-unknown');
        }

        return $user;
    }
}
