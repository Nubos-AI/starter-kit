<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Approvals\ApprovalEventType;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ApprovalEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $approval_process_id
 * @property string|null $approval_process_stage_id
 * @property string|null $actor_id
 * @property string|null $on_behalf_of_id
 * @property ApprovalEventType $type
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'approval_process_id',
        'approval_process_stage_id',
        'actor_id',
        'on_behalf_of_id',
        'type',
        'reason',
        'payload',
        'occurred_at',
    ]
)]
class ApprovalEvent extends Model
{
    /** @use HasFactory<ApprovalEventFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(__('i18n.backend.models.approval_event.approval_events_is_append_only_and_cannot_be_updated'));
        });

        static::deleting(function (): never {
            throw new RuntimeException(__('i18n.backend.models.approval_event.approval_events_is_append_only_and_cannot_be_deleted'));
        });
    }

    /**
     * @return BelongsTo<ApprovalProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(ApprovalProcess::class, 'approval_process_id');
    }

    /**
     * @return BelongsTo<ApprovalProcessStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalProcessStage::class, 'approval_process_stage_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApprovalEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
