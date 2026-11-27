<?php

declare(strict_types=1);

namespace App\Enums\Modules;

enum ModuleAccessVerdict: string
{
    case Allowed = 'allowed';

    case Unavailable = 'unavailable';

    case Forbidden = 'forbidden';
}
