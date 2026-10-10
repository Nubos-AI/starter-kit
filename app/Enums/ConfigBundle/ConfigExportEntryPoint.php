<?php

declare(strict_types=1);

namespace App\Enums\ConfigBundle;

enum ConfigExportEntryPoint: string
{
    case Download = 'download';

    case Console = 'console';
}
