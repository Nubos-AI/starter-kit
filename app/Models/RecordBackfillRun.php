<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Engine\RecordBackfillKind;
use App\Enums\Formulas\BackfillStatus;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\RecordBackfillRunFactory;
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
 * @property string|null $user_id
 * @property RecordBackfillKind $kind
 * @property BackfillStatus $status
 * @property int $total_count
 * @property int $processed_count
 * @property int $error_count
 * @property string|null $cursor_id
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'user_id',
        'kind',
        'status',
        'total_count',
        'processed_count',
        'error_count',
        'cursor_id',
        'finished_at',
    ]
)]
class RecordBackfillRun extends Model
{
    /** @use HasFactory<RecordBackfillRunFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RecordBackfillKind::class,
            'status' => BackfillStatus::class,
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'error_count' => 'integer',
            'finished_at' => 'datetime',
        ];
    }
}
