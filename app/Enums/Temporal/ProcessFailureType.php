<?php

declare(strict_types=1);

namespace App\Enums\Temporal;

enum ProcessFailureType: string
{
    case Rejected = 'ProcessStepRejected';

    case Conflicting = 'ProcessStepConflicting';
}
