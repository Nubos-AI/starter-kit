<?php

declare(strict_types=1);

namespace App\Support\Teams;

final class TeamSegment
{
    public static function key(): string
    {
        return 'activeTeam';
    }

    public static function pattern(): string
    {
        return '[a-zA-Z0-9-]+';
    }
}
