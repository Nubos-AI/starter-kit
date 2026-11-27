<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Engine\MergeRuleMode;
use App\Policies\Engine\MergeRulePolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\MergeRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $object_type_id
 * @property string $name
 * @property MergeRuleMode $mode
 * @property int $position
 * @property bool $is_active
 * @property string|null $deny_reason
 * @property array<string, mixed>|null $condition
 * @property array<string, string> $field_strategies
 * @property array<string, string> $transfer_policy
 * @property array<string, bool> $options
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'name',
        'mode',
        'position',
        'is_active',
        'deny_reason',
        'condition',
        'field_strategies',
        'transfer_policy',
        'options',
    ]
)]
#[UsePolicy(MergeRulePolicy::class)]
class MergeRule extends Model
{
    /** @use HasFactory<MergeRuleFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => MergeRuleMode::class,
            'position' => 'integer',
            'is_active' => 'boolean',
            'condition' => 'array',
            'field_strategies' => 'array',
            'transfer_policy' => 'array',
            'options' => 'array',
        ];
    }
}
