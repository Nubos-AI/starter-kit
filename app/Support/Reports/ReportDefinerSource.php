<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\User;

class ReportDefinerSource
{
    public function find(string $definerId): ?User
    {
        return User::query()->whereKey($definerId)->first();
    }
}
