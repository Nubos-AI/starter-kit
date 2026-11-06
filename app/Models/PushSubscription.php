<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * @property string $id
 * @property string $tenant_id
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
    ]
)]
class PushSubscription extends BasePushSubscription
{
    use BelongsToTenant;
    use HasUlids;
}
