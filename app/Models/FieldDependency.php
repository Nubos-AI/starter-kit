<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\FieldDependencyFactory;
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
 * @property string $rollup_field_id
 * @property string $depends_on_field_id
 * @property string|null $relationship_type_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(['tenant_id', 'rollup_field_id', 'depends_on_field_id', 'relationship_type_id'])]
class FieldDependency extends Model
{
    /** @use HasFactory<FieldDependencyFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<FieldDefinition, $this>
     */
    public function rollupField(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'rollup_field_id');
    }

    /**
     * @return BelongsTo<FieldDefinition, $this>
     */
    public function dependsOnField(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'depends_on_field_id');
    }

    /**
     * @return BelongsTo<RelationshipType, $this>
     */
    public function relationshipType(): BelongsTo
    {
        return $this->belongsTo(RelationshipType::class);
    }
}
