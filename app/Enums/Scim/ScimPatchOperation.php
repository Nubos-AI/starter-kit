<?php

declare(strict_types=1);

namespace App\Enums\Scim;

enum ScimPatchOperation: string
{
    case Add = 'add';

    case Replace = 'replace';

    case Remove = 'remove';
}
