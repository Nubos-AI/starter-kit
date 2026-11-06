<?php

declare(strict_types=1);

namespace App\Enums\Preferences;

enum ConfigurationGrid: string
{
    case ObjectTypes = 'object-types';

    case Roles = 'roles';

    case Teams = 'teams';

    case Trash = 'trash';

    case Users = 'users';
}
