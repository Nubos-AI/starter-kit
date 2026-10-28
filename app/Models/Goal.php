<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Goals\GoalDirection;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Policies\Goals\GoalPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $owner_id
 * @property string $report_id
 * @property string|null $target_user_id
 * @property string|null $target_team_id
 * @property bool $includes_subteams
 * @property string $name
 * @property GoalScopeType $scope_type
 * @property string|null $scope_field_key
 * @property string|null $period_field_key
 * @property GoalPeriodType $period_type
 * @property GoalDirection $direction
 * @property string $target_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(GoalPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'owner_id',
        'report_id',
        'target_user_id',
        'target_team_id',
        'includes_subteams',
        'name',
        'scope_type',
        'scope_field_key',
        'period_field_key',
        'period_type',
        'direction',
        'target_value',
    ]
)]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function targetTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'target_team_id');
    }

    /**
     * @return HasMany<GoalPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(GoalPeriod::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'includes_subteams' => 'boolean',
            'scope_type' => GoalScopeType::class,
            'period_type' => GoalPeriodType::class,
            'direction' => GoalDirection::class,
            'target_value' => 'decimal:4',
        ];
    }
}
