<?php

declare(strict_types=1);

namespace App\Enums\Recurrence;

enum SeriesScope: string
{
    case Occurrence = 'occurrence';

    case Series = 'series';
}
