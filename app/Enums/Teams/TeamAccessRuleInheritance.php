<?php

declare(strict_types=1);

namespace App\Enums\Teams;

enum TeamAccessRuleInheritance: string
{
    case Intersect = 'intersect';

    case Override = 'override';
}
