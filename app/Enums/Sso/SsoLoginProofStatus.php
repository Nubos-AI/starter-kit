<?php

declare(strict_types=1);

namespace App\Enums\Sso;

enum SsoLoginProofStatus: string
{
    case Verified = 'verified';

    case Outdated = 'outdated';

    case Missing = 'missing';
}
