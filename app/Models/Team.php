<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Exceptions\Authorization\EscalatedTeamRoleException;
use App\Observers\TeamTreeObserver;
use App\Policies\Teams\TeamPolicy;
use App\Scopes\TenantScope;
use App\Traits\Authorization\HasRoles;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_team_id
 * @property string|null $owner_id
 * @property string $name
 * @property string $slug
 * @property array<int, string>|null $ancestor_team_ids
 * @property array<int, string>|null $descendant_team_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ObservedBy([TeamTreeObserver::class])]
#[ScopedBy([TenantScope::class])]
#[UsePolicy(TeamPolicy::class)]
#[Fillable(['tenant_id', 'parent_team_id', 'owner_id', 'name', 'slug'])]
class Team extends Model implements PermissionHolderInterface
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasRoles {
        assignRole as private assignRoleUnguarded;
    }
    use HasUlids;
    use SoftDeletes;

    public function assignRole(Role|string $role, ?Model $scope = null): RoleAssignment
    {
        $resolved = $role instanceof Role
            ? $role
            : Role::query()->where('name', $role)->firstOrFail();

        if ($resolved->authority !== null) {
            throw EscalatedTeamRoleException::forRole($resolved->name);
        }

        return $this->assignRoleUnguarded($resolved, $scope);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ancestor_team_ids' => 'array',
            'descendant_team_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_team_id');
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_team_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this
            ->belongsToMany(User::class)
            ->withTimestamps();
    }
}
