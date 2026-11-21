<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum RollupScope: string
{
    case DirectChildren = 'direct_children';

    case Subtree = 'subtree';
}
