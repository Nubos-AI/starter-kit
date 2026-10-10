<?php

declare(strict_types=1);

namespace App\Enums\Timeline;

enum TimelineChannel: string
{
    case Web = 'web';

    case Api = 'api';

    case Automation = 'automation';

    case System = 'system';
}
