<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Policies\Teams\TeamRecordAccessRulePolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\TeamRecordAccessRuleFactory;
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
 * @property string $team_id
 * @property string $object_type_id
 * @property string|null $created_by_id
 * @property bool $is_active
 * @property TeamAccessRuleInheritance $inheritance
 * @property array<string, mixed> $filter_definition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(TeamRecordAccessRulePolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'team_id',
        'object_type_id',
        'created_by_id',
        'is_active',
        'inheritance',
        'filter_definition',
    ]
)]
class TeamRecordAccessRule extends Model
{
    /** @use HasFactory<TeamRecordAccessRuleFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'inheritance' => TeamAccessRuleInheritance::class,
            'filter_definition' => 'array',
        ];
    }
}
