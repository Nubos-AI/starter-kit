<?php

declare(strict_types=1);

namespace App\Enums\Watchers;

enum WatcherSource: string
{
    case Auto = 'auto';

    case Manual = 'manual';

    case Collaborator = 'collaborator';
}
