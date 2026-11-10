<?php

declare(strict_types=1);

namespace App\Enums\Tenancy;

enum DomainProofPurpose: string
{
    case Sso = 'sso';

    case Site = 'site';
}
