<?php

declare(strict_types=1);

namespace App\Enums\Api;

enum SearchSource: string
{
    case Index = 'index';

    case Fallback = 'fallback';
}
