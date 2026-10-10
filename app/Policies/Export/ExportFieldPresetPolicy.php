<?php

declare(strict_types=1);

namespace App\Policies\Export;

use App\Models\ExportFieldPreset;
use App\Models\User;
use App\Support\Authorization\TenantBoundary;
use App\Traits\Authorization\AuthorizesCreatorOrAdmin;

class ExportFieldPresetPolicy
{
    use AuthorizesCreatorOrAdmin;

    public function __construct(private readonly TenantBoundary $tenantBoundary) {}

    public function update(User $user, ExportFieldPreset $preset): bool
    {
        return $this->isCreatorOrAdmin($user, $preset, $preset->user_id);
    }

    public function delete(User $user, ExportFieldPreset $preset): bool
    {
        return $this->isCreatorOrAdmin($user, $preset, $preset->user_id);
    }
}
