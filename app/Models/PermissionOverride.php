<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Authorization\PermissionEffect;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $permission_id
 * @property string $model_type
 * @property string $model_id
 * @property PermissionEffect $effect
 * @property string|null $scope_type
 * @property string|null $scope_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(['tenant_id', 'permission_id', 'effect', 'scope_type', 'scope_id'])]
class PermissionOverride extends MorphPivot
{
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
        ];
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
