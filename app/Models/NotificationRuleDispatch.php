<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $rule_id
 * @property string $record_id
 * @property string $user_id
 * @property string $stage
 * @property Carbon $dispatched_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'rule_id',
        'record_id',
        'user_id',
        'stage',
        'dispatched_at',
    ]
)]
class NotificationRuleDispatch extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    /**
     * @return BelongsTo<NotificationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
        ];
    }
}
