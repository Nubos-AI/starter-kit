<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum MergeTransferPolicy: string
{
    case Move = 'move';

    case Keep = 'keep';

    case Discard = 'discard';
}
