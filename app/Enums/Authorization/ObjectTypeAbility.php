<?php

declare(strict_types=1);

namespace App\Enums\Authorization;

enum ObjectTypeAbility: string
{
    case Import = 'import';

    case Export = 'export';

    case WatchersManage = 'watchers.manage';

    case RulesManage = 'rules.manage';

    case AuditView = 'audit.view';

    case Reparent = 'reparent';

    case Merge = 'merge';
}
