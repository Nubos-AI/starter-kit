<?php

declare(strict_types=1);

namespace App\Support\Teams;

use Illuminate\Support\Facades\URL;

class ActiveTeamUrlDefault
{
    public function apply(?string $segment): void
    {
        URL::defaults([TeamSegment::key() => $segment]);
    }

    public function current(): ?string
    {
        $segment = URL::getDefaultParameters()[TeamSegment::key()] ?? null;

        return is_string($segment) && $segment !== ''
            ? $segment
            : null;
    }
}
