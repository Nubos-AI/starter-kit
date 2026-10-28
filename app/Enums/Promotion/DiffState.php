<?php

declare(strict_types=1);

namespace App\Enums\Promotion;

enum DiffState: string
{
    case Added = 'added';

    case Removed = 'removed';

    case Modified = 'modified';

    case Unchanged = 'unchanged';

    case Conflicted = 'conflicted';
}
