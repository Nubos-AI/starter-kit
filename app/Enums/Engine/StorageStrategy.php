<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum StorageStrategy: string
{
    case Native = 'native';

    case Generic = 'generic';
}
