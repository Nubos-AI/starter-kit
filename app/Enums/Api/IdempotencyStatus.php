<?php

declare(strict_types=1);

namespace App\Enums\Api;

enum IdempotencyStatus: string
{
    case InProgress = 'in_progress';

    case Completed = 'completed';
}
