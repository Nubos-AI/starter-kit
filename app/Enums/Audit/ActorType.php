<?php

declare(strict_types=1);

namespace App\Enums\Audit;

enum ActorType: string
{
    case User = 'user';

    case Automation = 'automation';

    case System = 'system';
}
