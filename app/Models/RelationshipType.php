<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
use App\Policies\Engine\RelationshipTypePolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\RelationshipTypeFactory;
use Illuminate\Auth\Access\AuthorizationException;
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
 * @property string $key
 * @property string $inverse_key
 * @property string $name
 * @property string $inverse_name
 * @property string $from_object_type_id
 * @property string $to_object_type_id
 * @property RelationCardinality $cardinality
 * @property bool $is_required
 * @property bool $is_hierarchy
 * @property CascadeBehavior $cascade_behavior
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'key',
        'inverse_key',
        'name',
        'inverse_name',
        'from_object_type_id',
        'to_object_type_id',
        'cardinality',
        'is_required',
        'is_hierarchy',
        'cascade_behavior',
    ]
)]
#[UsePolicy(RelationshipTypePolicy::class)]
class RelationshipType extends Model
{
    /** @use HasFactory<RelationshipTypeFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (RelationshipType $type): void {
            if ($type->isDirty('key') && $type->getOriginal('key') !== null) {
                throw new AuthorizationException(__('i18n.backend.models.relationship_type.the_key_of_a_relationship_type_is_immutable_once'));
            }

            if ($type->isDirty('inverse_key') && $type->getOriginal('inverse_key') !== null) {
                throw new AuthorizationException(__('i18n.backend.models.relationship_type.the_inverse_key_of_a_relationship_type_is_immutable'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cardinality' => RelationCardinality::class,
            'cascade_behavior' => CascadeBehavior::class,
            'is_required' => 'boolean',
            'is_hierarchy' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function fromObjectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class, 'from_object_type_id');
    }

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function toObjectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class, 'to_object_type_id');
    }
}
