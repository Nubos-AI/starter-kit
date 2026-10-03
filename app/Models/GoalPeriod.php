<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\GoalPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $goal_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string|null $current_value
 * @property Carbon|null $calculated_at
 * @property array<string, mixed> $triggered_thresholds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'goal_id',
        'period_start',
        'period_end',
        'current_value',
        'calculated_at',
        'triggered_thresholds',
    ]
)]
class GoalPeriod extends Model
{
    /** @use HasFactory<GoalPeriodFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * @return Attribute<array<string, mixed>, string>
     */
    protected function triggeredThresholds(): Attribute
    {
        return Attribute::make(
            get: static function (?string $value): array {
                $decoded = $value === null ? [] : Json::decode($value);

                return is_array($decoded) ? $decoded : [];
            },
            set: static fn (array $value): string => (string) Json::encode($value, JSON_FORCE_OBJECT),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'current_value' => 'decimal:4',
            'calculated_at' => 'datetime',
        ];
    }
}
