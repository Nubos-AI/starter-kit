<?php

declare(strict_types=1);

namespace App\Enums\Export;

enum ExportJobStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';
}
