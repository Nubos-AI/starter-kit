<?php

declare(strict_types=1);

namespace App\Enums\Import;

enum ImportJobStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';
}
