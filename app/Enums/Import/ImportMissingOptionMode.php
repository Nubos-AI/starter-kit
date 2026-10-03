<?php

declare(strict_types=1);

namespace App\Enums\Import;

enum ImportMissingOptionMode: string
{
    case Create = 'create';

    case Error = 'error';
}
