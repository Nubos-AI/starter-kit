<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum AgingClock: string
{
    case UpdatedAt = 'updated_at';

    case StageEnteredAt = 'stage_entered_at';

    case Field = 'field';
}
