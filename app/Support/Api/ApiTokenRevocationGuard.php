<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\User;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Http\IdentifierList;
use App\Support\Users\ManageableUserDirectory;

class ApiTokenRevocationGuard
{
    public function __construct(
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly ManageableUserDirectory $users,
    ) {}

    public function mayRevoke(User $actor, User $owner): bool
    {
        return $this->isOwnOrService($actor, $owner) || $this->subsetGuard->maySteerAccount($actor, $owner);
    }

    /**
     * @param  iterable<User>  $owners
     * @return list<string>
     */
    public function revocableOwnerKeys(User $actor, iterable $owners): array
    {
        $unjudged = [];
        $judged = [];

        foreach ($owners as $owner) {
            if ($this->isOwnOrService($actor, $owner)) {
                $unjudged[] = (string) $owner->getKey();

                continue;
            }

            $judged[] = (string) $owner->getKey();
        }

        $judged = IdentifierList::from($judged);

        $steerable = $judged === []
            ? []
            : $this->subsetGuard->steerableAccountKeys(
                $actor,
                $judged,
                $this->users->roleIdsByUser($judged),
                $this->users->teamIdsByUser($judged),
            );

        return IdentifierList::from([...$unjudged, ...$steerable]);
    }

    private function isOwnOrService(User $actor, User $owner): bool
    {
        return $owner->is_service || (string) $owner->getKey() === (string) $actor->getKey();
    }
}
