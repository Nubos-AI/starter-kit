<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Webhooks\WebhookEventType;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\WebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $target_url
 * @property string|null $auth_username
 * @property string|null $auth_password
 * @property string $secret
 * @property string|null $secret_previous
 * @property Carbon|null $rotated_at
 * @property WebhookSubscriptionStatus $status
 * @property string|null $service_user_id
 * @property string|null $role_id
 * @property Collection<int, WebhookEventType> $event_types
 * @property string|null $object_type_id
 * @property int $consecutive_failures
 * @property Carbon|null $activated_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'name',
        'target_url',
        'auth_username',
        'auth_password',
        'secret',
        'secret_previous',
        'rotated_at',
        'status',
        'service_user_id',
        'role_id',
        'event_types',
        'object_type_id',
        'consecutive_failures',
        'activated_at',
        'last_error',
    ]
)]
#[Hidden(['secret', 'secret_previous', 'auth_password'])]
class WebhookSubscription extends Model
{
    /** @use HasFactory<WebhookSubscriptionFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'secret_previous' => 'encrypted',
            'auth_password' => 'encrypted',
            'rotated_at' => 'datetime',
            'status' => WebhookSubscriptionStatus::class,
            'event_types' => AsEnumCollection::of(WebhookEventType::class),
            'consecutive_failures' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function serviceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_user_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }
}
