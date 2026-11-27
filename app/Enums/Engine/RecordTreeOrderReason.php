<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum RecordTreeOrderReason: string
{
    case NotRequested = 'not_requested';

    case NoHierarchy = 'no_hierarchy';

    case ExplicitSort = 'explicit_sort';

    case TooManyRows = 'too_many_rows';
}
