<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Notifications\RuleTriggerType;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\NotificationRuleFactory;
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
 * @property string $name
 * @property RuleTriggerType $trigger_type
 * @property array<string, mixed>|null $config
 * @property string|null $segment_id
 * @property array<string, mixed>|null $filter_definition
 * @property array<string, mixed>|null $action
 * @property bool $is_active
 * @property string $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'name',
        'trigger_type',
        'config',
        'segment_id',
        'filter_definition',
        'action',
        'is_active',
        'created_by_id',
    ]
)]
class NotificationRule extends Model
{
    /** @use HasFactory<NotificationRuleFactory> */
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
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => RuleTriggerType::class,
            'config' => 'array',
            'filter_definition' => 'array',
            'action' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
