<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Maintenance\MaintenanceLockReason;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\MaintenanceLockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $acquired_by_id
 * @property string|null $released_by_id
 * @property MaintenanceLockReason $reason
 * @property MaintenanceLockStatus $status
 * @property MaintenanceLockRelease|null $release_mode
 * @property list<string>|null $suspended_schedule_ids
 * @property string|null $note
 * @property string|null $release_note
 * @property Carbon $acquired_at
 * @property Carbon|null $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'acquired_by_id',
        'released_by_id',
        'reason',
        'status',
        'release_mode',
        'suspended_schedule_ids',
        'note',
        'release_note',
        'acquired_at',
        'released_at',
    ]
)]
class MaintenanceLock extends Model
{
    /** @use HasFactory<MaintenanceLockFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function acquiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acquired_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => MaintenanceLockReason::class,
            'status' => MaintenanceLockStatus::class,
            'release_mode' => MaintenanceLockRelease::class,
            'suspended_schedule_ids' => 'array',
            'acquired_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
