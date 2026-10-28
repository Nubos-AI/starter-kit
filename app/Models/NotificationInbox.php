<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\NotificationInboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property int $priority
 * @property Carbon|null $read_at
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Table('notification_inbox')]
#[Fillable(
    [
        'tenant_id',
        'user_id',
        'type',
        'data',
        'priority',
        'read_at',
        'snoozed_until',
        'archived_at',
    ]
)]
class NotificationInbox extends Model
{
    /** @use HasFactory<NotificationInboxFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

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
            'data' => 'array',
            'priority' => 'integer',
            'read_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
