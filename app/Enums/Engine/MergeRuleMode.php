<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum MergeRuleMode: string
{
    case Allow = 'allow';

    case Deny = 'deny';
}
