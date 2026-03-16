<?php

declare(strict_types=1);

namespace App\Enums;

enum Salutation: string
{
    case MR = 'mr';
    case MRS = 'mrs';
    case DIVERS = 'divers';
}
