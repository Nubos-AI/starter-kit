<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum MergeValueOrigin: string
{
    case Target = 'target';

    case Source = 'source';

    case Combined = 'combined';

    case Undecided = 'undecided';
}
