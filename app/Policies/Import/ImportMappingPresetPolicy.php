<?php

declare(strict_types=1);

namespace App\Policies\Import;

use App\Models\ImportMappingPreset;
use App\Models\User;
use App\Support\Authorization\TenantBoundary;
use App\Traits\Authorization\AuthorizesCreatorOrAdmin;

class ImportMappingPresetPolicy
{
    use AuthorizesCreatorOrAdmin;

    public function __construct(private readonly TenantBoundary $tenantBoundary) {}

    public function update(User $user, ImportMappingPreset $preset): bool
    {
        return $this->isCreatorOrAdmin($user, $preset, $preset->user_id);
    }

    public function delete(User $user, ImportMappingPreset $preset): bool
    {
        return $this->isCreatorOrAdmin($user, $preset, $preset->user_id);
    }
}
