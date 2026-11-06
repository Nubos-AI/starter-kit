<?php

declare(strict_types=1);

namespace App\Enums\Authorization;

enum CrudAction: string
{
    case View = 'view';

    case Create = 'create';

    case Update = 'update';

    case Delete = 'delete';
}
