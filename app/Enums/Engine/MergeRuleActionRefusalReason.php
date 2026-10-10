<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum MergeRuleActionRefusalReason: string
{
    case NotPermitted = 'not_permitted';
}
