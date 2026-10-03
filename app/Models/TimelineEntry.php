<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\TimelineEntryFactory;
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
 * @property string $record_id
 * @property string $source_key
 * @property string|null $source_id
 * @property string|null $actor_id
 * @property string|null $actor_type
 * @property string|null $channel
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'record_id',
        'source_key',
        'source_id',
        'actor_id',
        'actor_type',
        'channel',
        'occurred_at',
        'payload',
    ]
)]
class TimelineEntry extends Model
{
    /** @use HasFactory<TimelineEntryFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<CustomRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class, 'record_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
