<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\NotificationChannel;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\NotificationTypeDefaultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property NotificationChannel $channel
 * @property bool $enabled
 * @property DeliveryMode $delivery_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'type',
        'channel',
        'enabled',
        'delivery_mode',
    ]
)]
class NotificationTypeDefault extends Model
{
    /** @use HasFactory<NotificationTypeDefaultFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
            'delivery_mode' => DeliveryMode::class,
        ];
    }
}
