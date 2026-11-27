<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\Governance\AbsenceDelegationPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\AbsenceDelegationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $delegate_id
 * @property string|null $created_by_id
 * @property string|null $updated_by_id
 * @property string|null $deleted_by_id
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(AbsenceDelegationPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'user_id',
        'delegate_id',
        'created_by_id',
        'updated_by_id',
        'deleted_by_id',
        'starts_at',
        'ends_at',
    ]
)]
class AbsenceDelegation extends Model
{
    /** @use HasFactory<AbsenceDelegationFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @param  Builder<AbsenceDelegation>  $query
     * @return Builder<AbsenceDelegation>
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Builder
    {
        return $query
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    /**
     * @param  Builder<AbsenceDelegation>  $query
     * @return Builder<AbsenceDelegation>
     */
    #[Scope]
    protected function coveringAt(Builder $query, CarbonImmutable $at): Builder
    {
        return $query
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
