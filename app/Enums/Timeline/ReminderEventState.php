<?php

declare(strict_types=1);

namespace App\Enums\Timeline;

enum ReminderEventState: string
{
    case Created = 'created';

    case Due = 'due';

    case Completed = 'completed';
}
