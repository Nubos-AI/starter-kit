<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\Tenant;
use App\Models\User;

interface ModuleAvailabilityInterface
{
    public function isAvailable(string $module, ?string $feature, ?Tenant $tenant): bool;

    public function isUsableBy(string $module, ?string $feature, User $user): bool;
}
