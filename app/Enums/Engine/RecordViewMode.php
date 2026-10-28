<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum RecordViewMode: string
{
    case Table = 'table';

    case Kanban = 'kanban';
}
