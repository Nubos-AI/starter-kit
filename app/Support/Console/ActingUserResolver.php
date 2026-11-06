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
            throw new MissingActingUserException('Die Option --as-user ist erforderlich.', 'acting-user-missing');
        }

        $user = $this->find($identifier);

        if ($user->trashed()) {
            throw new MissingActingUserException('Der unter --as-user angegebene Handelnde ist gelöscht.', 'acting-user-trashed');
        }

        if ($user->tenant_id === null) {
            throw new MissingActingUserException('Der unter --as-user angegebene Handelnde gehört zu keinem Mandanten.', 'acting-user-without-tenant');
        }

        if (!Tenant::query()->whereKey($user->tenant_id)->exists()) {
            throw new MissingActingUserException('Der Mandant des unter --as-user angegebenen Handelnden existiert nicht mehr.', 'acting-user-tenant-missing');
        }

        if ($user->is_service) {
            throw new MissingActingUserException('Der unter --as-user angegebene Handelnde ist ein Dienstkonto und darf nicht als Handelnder auftreten.', 'acting-user-is-service');
        }

        if (!$user->status->canAuthenticate()) {
            throw new MissingActingUserException('Der unter --as-user angegebene Handelnde hat seine Einladung nicht angenommen oder ist gesperrt.', 'acting-user-not-accepted');
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
            throw new MissingActingUserException('Die Option --as-user erwartet eine E-Mail-Adresse oder eine ULID.', 'acting-user-malformed');
        }

        $query = User::query()->withTrashed();

        $user = $isUlid
            ? $query->whereKey($identifier)->first()
            : $query->where('email', $identifier)->first();

        if (!$user instanceof User) {
            throw new MissingActingUserException('Der unter --as-user angegebene Handelnde ist unbekannt.', 'acting-user-unknown');
        }

        return $user;
    }
}
