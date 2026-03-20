<?php

declare(strict_types=1);

namespace Nubos\Init\Enums;

enum DatabaseStrategy: string
{
    case SingleDatabase = 'Single-Database';
    case MultiDatabase = 'Multi-Database';
}
