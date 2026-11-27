<?php

declare(strict_types=1);

namespace App\Traits\Api;

use DateTimeInterface;
use Illuminate\Support\Carbon;

trait ResolvesTokenExpiry
{
    private function tokenExpiry(mixed $expiresAt): DateTimeInterface
    {
        return is_string($expiresAt) && $expiresAt !== ''
            ? Carbon::parse($expiresAt)
            : now()->addDays((int) config('sanctum.default_token_lifetime_days'));
    }
}
