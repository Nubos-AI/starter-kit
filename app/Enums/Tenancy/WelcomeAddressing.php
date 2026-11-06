<?php

declare(strict_types=1);

namespace App\Enums\Tenancy;

enum WelcomeAddressing: string
{
    case Path = 'path';

    case Host = 'host';
}
