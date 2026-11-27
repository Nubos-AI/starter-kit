<?php

declare(strict_types=1);

namespace App\Enums\Ui;

enum Appearance: string
{
    case Light = 'light';

    case Dark = 'dark';

    case System = 'system';
}
