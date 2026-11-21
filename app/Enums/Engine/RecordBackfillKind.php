<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum RecordBackfillKind: string
{
    case TimelineProjection = 'timeline_projection';

    case SearchText = 'search_text';
}
