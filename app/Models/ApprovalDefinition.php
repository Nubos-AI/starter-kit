<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\Scopes\TenantScope;
use App\Traits\Modules\HasModuleAttributes;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ApprovalDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $anchor_type
 * @property string|null $anchor_id
 * @property string|null $rejection_stage_transition_id
 * @property bool $is_active
 * @property array<string, bool> $exclusions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'anchor_type',
        'anchor_id',
        'is_active',
        'exclusions',
        'rejection_stage_transition_id',
    ]
)]
class ApprovalDefinition extends Model
{
    /** @use HasFactory<ApprovalDefinitionFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use HasModuleAttributes;
    use SoftDeletes;

    /**
     * @return HasMany<ApprovalDefinitionStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalDefinitionStage::class);
    }

    /**
     * @return Attribute<array{trigger: bool, last_editor: bool, creator: bool, owner: bool}, string>
     */
    protected function exclusions(): Attribute
    {
        return Attribute::make(
            get: static function (?string $value): array {
                $decoded = $value === null ? [] : Json::decode($value);

                return ApprovalExclusionSet::fromArray(is_array($decoded) ? $decoded : [])->toArray();
            },
            set: static fn (array $value): string => (string) Json::encode(
                ApprovalExclusionSet::fromArray($value)->toArray(),
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
