<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\ModuleAvailabilityInterface;
use App\Models\Tenant;
use App\Models\User;

class AllowAllModules implements ModuleAvailabilityInterface
{
    public function isAvailable(string $module, ?string $feature, ?Tenant $tenant): bool
    {
        return true;
    }

    public function isUsableBy(string $module, ?string $feature, User $user): bool
    {
        return true;
    }
}
