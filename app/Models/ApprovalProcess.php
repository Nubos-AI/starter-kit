<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Approvals\ApprovalProcessStatus;
use App\Policies\Approvals\ApprovalProcessPolicy;
use App\Scopes\TenantScope;
use App\Traits\Modules\HasModuleAttributes;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ApprovalProcessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $record_id
 * @property string $approval_definition_id
 * @property string $anchor_type
 * @property string $anchor_id
 * @property string|null $from_stage_id
 * @property string|null $to_stage_id
 * @property string|null $triggered_by_id
 * @property ApprovalProcessStatus $status
 * @property int $attempt
 * @property int|null $current_stage_position
 * @property int|null $record_version
 * @property string|null $cancellation_reason
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(ApprovalProcessPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'record_id',
        'approval_definition_id',
        'anchor_type',
        'anchor_id',
        'triggered_by_id',
        'status',
        'attempt',
        'current_stage_position',
        'record_version',
        'cancellation_reason',
        'started_at',
        'finished_at',
    ]
)]
class ApprovalProcess extends Model
{
    /** @use HasFactory<ApprovalProcessFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use HasModuleAttributes;
    use SoftDeletes;

    /**
     * @return BelongsTo<CustomRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class, 'record_id');
    }

    /**
     * @return BelongsTo<ApprovalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ApprovalDefinition::class, 'approval_definition_id')->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function anchor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ApprovalProcessStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalProcessStage::class);
    }

    /**
     * @return HasMany<ApprovalEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class);
    }

    public function currentStage(): ?ApprovalProcessStage
    {
        if ($this->current_stage_position === null) {
            return null;
        }

        return $this->stages()
            ->where('attempt', $this->attempt)
            ->where('position', $this->current_stage_position)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalProcessStatus::class,
            'attempt' => 'integer',
            'current_stage_position' => 'integer',
            'record_version' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
