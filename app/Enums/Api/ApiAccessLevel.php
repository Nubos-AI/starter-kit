<?php

declare(strict_types=1);

namespace App\Enums\Api;

enum ApiAccessLevel: string
{
    case Read = 'read';

    case Write = 'write';
}
