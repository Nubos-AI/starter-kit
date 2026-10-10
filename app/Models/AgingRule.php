<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Engine\AgingClock;
use App\Policies\Aging\AgingRulePolicy;
use App\Scopes\ObjectTypeTenantScope;
use Database\Factories\AgingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $object_type_id
 * @property string $name
 * @property AgingClock $clock
 * @property string|null $clock_field_key
 * @property array<string, mixed>|null $condition
 * @property list<array{after_days: int, color: string}> $thresholds
 * @property bool $is_active
 * @property bool $triggers_automation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([ObjectTypeTenantScope::class])]
#[Fillable(
    [
        'object_type_id',
        'name',
        'clock',
        'clock_field_key',
        'condition',
        'thresholds',
        'is_active',
        'triggers_automation',
    ]
)]
#[UsePolicy(AgingRulePolicy::class)]
class AgingRule extends Model
{
    /** @use HasFactory<AgingRuleFactory> */
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock' => AgingClock::class,
            'condition' => 'array',
            'thresholds' => 'array',
            'is_active' => 'boolean',
            'triggers_automation' => 'boolean',
        ];
    }
}
