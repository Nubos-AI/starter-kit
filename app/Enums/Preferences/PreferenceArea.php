<?php

declare(strict_types=1);

namespace App\Enums\Preferences;

enum PreferenceArea: string
{
    case Global = 'global';

    case Records = 'records';

    case Configuration = 'configuration';
}
