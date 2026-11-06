<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\RecordMergeFactory;
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
 * @property string $object_type_id
 * @property string $target_record_id
 * @property string $source_record_id
 * @property string|null $merge_rule_id
 * @property string|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed> $resolution
 * @property array<string, mixed> $transfers
 * @property Carbon|null $undone_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'target_record_id',
        'source_record_id',
        'merge_rule_id',
        'actor_id',
        'reason',
        'resolution',
        'transfers',
        'undone_at',
    ]
)]
#[ScopedBy([TenantScope::class])]
class RecordMerge extends Model
{
    /** @use HasFactory<RecordMergeFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<CustomRecord, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class, 'target_record_id');
    }

    /**
     * @return BelongsTo<CustomRecord, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class, 'source_record_id');
    }

    /**
     * @return BelongsTo<MergeRule, $this>
     */
    public function mergeRule(): BelongsTo
    {
        return $this->belongsTo(MergeRule::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolution' => 'array',
            'transfers' => 'array',
            'undone_at' => 'datetime',
        ];
    }
}
