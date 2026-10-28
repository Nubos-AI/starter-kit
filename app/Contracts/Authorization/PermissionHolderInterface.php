<?php

declare(strict_types=1);

namespace App\Contracts\Authorization;

use App\Enums\Authorization\PermissionEffect;
use App\Models\PermissionOverride;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

interface PermissionHolderInterface
{
    /**
     * @return MorphMany<PermissionOverride, covariant Model>
     */
    public function permissionOverrides(): MorphMany;

    /**
     * @return Collection<int, Role>
     */
    public function assignedRolesFor(?Model $scope = null): Collection;

    /**
     * @return array<string, PermissionEffect>
     */
    public function assignedOverridesFor(?Model $scope = null): array;

    public function forgetResolvedRoles(): void;
}
