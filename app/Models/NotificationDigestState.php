<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Notifications\DigestFrequency;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\NotificationDigestStateFactory;
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
 * @property DigestFrequency|null $frequency
 * @property int $hour
 * @property Carbon|null $last_digest_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Table('notification_digest_state')]
#[Fillable(
    [
        'tenant_id',
        'user_id',
        'frequency',
        'hour',
        'last_digest_sent_at',
    ]
)]
class NotificationDigestState extends Model
{
    /** @use HasFactory<NotificationDigestStateFactory> */
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
            'frequency' => DigestFrequency::class,
            'hour' => 'integer',
            'last_digest_sent_at' => 'datetime',
        ];
    }
}
