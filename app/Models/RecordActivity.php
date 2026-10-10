<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\RecordActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $record_id
 * @property string|null $activity_type_id
 * @property string|null $assignee_id
 * @property string|null $creator_id
 * @property string $subject
 * @property Carbon $occurred_at
 * @property string|null $result
 * @property-read CustomRecord $record
 * @property-read ActivityType|null $activityType
 * @property-read User|null $assignee
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(['tenant_id', 'record_id', 'activity_type_id', 'assignee_id', 'creator_id', 'subject', 'occurred_at', 'result'])]
class RecordActivity extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<RecordActivityFactory> */
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /** @return BelongsTo<CustomRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class, 'record_id');
    }

    /** @return BelongsTo<ActivityType, $this> */
    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
