<?php

declare(strict_types=1);

namespace App\Enums\Tenancy;

enum DomainPointing: string
{
    case Pointing = 'pointing';

    case Elsewhere = 'elsewhere';

    case Unproven = 'unproven';
}
