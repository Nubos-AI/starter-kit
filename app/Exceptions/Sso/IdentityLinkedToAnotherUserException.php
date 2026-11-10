<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use App\Enums\Sso\IdentityProvider;
use RuntimeException;

class IdentityLinkedToAnotherUserException extends RuntimeException
{
    public function __construct(public readonly IdentityProvider $provider)
    {
        parent::__construct("The {$provider->value} identity is already linked to another user.");
    }
}
