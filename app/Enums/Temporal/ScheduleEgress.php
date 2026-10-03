<?php

declare(strict_types=1);

namespace App\Enums\Temporal;

enum ScheduleEgress: string
{
    case Internal = 'internal';

    case External = 'external';
}
