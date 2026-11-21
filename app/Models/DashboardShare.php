<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\DashboardShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $dashboard_id
 * @property string $grantee_type
 * @property string $grantee_id
 * @property bool $can_edit
 * @property string|null $granted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(['tenant_id', 'dashboard_id', 'grantee_type', 'grantee_id', 'can_edit', 'granted_by'])]
class DashboardShare extends MorphPivot
{
    /** @use HasFactory<DashboardShareFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<Dashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function grantee(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_edit' => 'boolean',
        ];
    }
}
