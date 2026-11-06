<?php

declare(strict_types=1);

namespace App\Enums\Sso;

enum IdentityProvider: string
{
    case Oidc = 'oidc';

    case Scim = 'scim';
}
