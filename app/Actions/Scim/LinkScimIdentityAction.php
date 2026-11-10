<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Sso\LinkUserIdentityAction;
use App\Enums\Sso\IdentityProvider;
use App\Exceptions\Scim\ScimException;
use App\Exceptions\Sso\IdentityLinkedToAnotherUserException;
use App\Models\User;
use App\Models\UserIdentity;

class LinkScimIdentityAction
{
    public function __construct(
        private readonly LinkUserIdentityAction $linkIdentity,
    ) {}

    /**
     * @throws ScimException
     */
    public function execute(User $user, string $externalId): UserIdentity
    {
        try {
            return $this->linkIdentity->execute($user, IdentityProvider::Scim, CreateScimUserAction::$issuer, $externalId);
        } catch (IdentityLinkedToAnotherUserException) {
            throw ScimException::externalIdTaken();
        }
    }
}
