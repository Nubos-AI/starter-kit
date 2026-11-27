<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Authorization\RoleScope;
use App\Observers\RoleAuditObserver;
use App\Policies\Authorization\RolePolicy;
use App\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property RoleScope $scope
 * @property RoleAuthority|null $authority
 * @property bool $is_system
 * @property bool $grants_subteam_visibility
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[ObservedBy([RoleAuditObserver::class])]
#[UsePolicy(RolePolicy::class)]
#[Fillable(['tenant_id', 'name', 'scope', 'authority', 'is_system', 'grants_subteam_visibility'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (Role $role): void {
            self::guardSystemRecord($role);
        });

        static::deleting(function (Role $role): void {
            self::guardSystemRecord($role);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'authority' => RoleAuthority::class,
            'is_system' => 'boolean',
            'grants_subteam_visibility' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')
            ->withPivotValue('tenant_id', TenantContext::currentId($this->tenant_id) ?? '');
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    private static function guardSystemRecord(Role $role): void
    {
        if (!$role->getOriginal('is_system')) {
            return;
        }

        $actor = Auth::user();

        if ($actor instanceof User && $actor->isEscalatedAuthority()) {
            return;
        }

        throw new AuthorizationException(__('i18n.backend.models.role.system_roles_cannot_be_modified_or_deleted'));
    }
}
