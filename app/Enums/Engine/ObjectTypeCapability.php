<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum ObjectTypeCapability: string
{
    case Records = 'records';

    case CustomFields = 'custom_fields';

    case Pipelines = 'pipelines';

    case Relations = 'relations';

    case Hierarchy = 'hierarchy';

    case Kanban = 'kanban';

    case Timeline = 'timeline';

    case Merge = 'merge';

    case Import = 'import';

    case Export = 'export';

    case Aging = 'aging';

    case Rollups = 'rollups';

    case Trash = 'trash';

    case BulkActions = 'bulk_actions';

    case Lookup = 'lookup';
}
