<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Authorization\RoleScope;
use App\Policies\Authorization\PermissionPolicy;
use App\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\PermissionFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $group
 * @property RoleScope $scope
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(PermissionPolicy::class)]
#[Fillable(['tenant_id', 'name', 'group', 'scope', 'is_system'])]
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (Permission $permission): void {
            self::guardSystemRecord($permission);
        });

        static::deleting(function (Permission $permission): void {
            self::guardSystemRecord($permission);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')
            ->withPivotValue('tenant_id', TenantContext::currentId($this->tenant_id) ?? '');
    }

    private static function guardSystemRecord(Permission $permission): void
    {
        if ($permission->getOriginal('is_system')) {
            throw new AuthorizationException(__('i18n.backend.models.permission.system_permissions_cannot_be_modified_or_deleted'));
        }
    }
}
