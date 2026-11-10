<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Approvals\ApprovalStageStatus;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ApprovalProcessStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
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
 * @property string $approval_process_id
 * @property string $approval_definition_stage_id
 * @property string|null $assigned_user_id
 * @property bool $escalation_applied
 * @property int $position
 * @property int $attempt
 * @property ApprovalStageStatus $status
 * @property Carbon|null $deadline_at
 * @property array<string, string>|null $escalation_added_delegations
 * @property Carbon $started_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'approval_process_id',
        'approval_definition_stage_id',
        'assigned_user_id',
        'escalation_applied',
        'position',
        'attempt',
        'status',
        'deadline_at',
        'escalation_added_delegations',
        'started_at',
        'decided_at',
    ]
)]
class ApprovalProcessStage extends Model
{
    /** @use HasFactory<ApprovalProcessStageFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<ApprovalProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(ApprovalProcess::class, 'approval_process_id');
    }

    /**
     * @return BelongsTo<ApprovalDefinitionStage, $this>
     */
    public function definitionStage(): BelongsTo
    {
        return $this->belongsTo(ApprovalDefinitionStage::class, 'approval_definition_stage_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<ApprovalEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'escalation_applied' => 'boolean',
            'position' => 'integer',
            'attempt' => 'integer',
            'status' => ApprovalStageStatus::class,
            'deadline_at' => 'datetime',
            'escalation_added_delegations' => 'array',
            'started_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
