<?php

declare(strict_types=1);

namespace App\Enums\Authorization;

enum PermissionEffect: string
{
    case Allow = 'allow';

    case Deny = 'deny';
}
