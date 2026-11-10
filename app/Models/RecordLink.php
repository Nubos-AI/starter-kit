<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Engine\RelationCardinality;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\RecordLinkFactory;
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
 * @property string $relationship_type_id
 * @property string|null $from_record_type
 * @property string $from_record_id
 * @property string|null $to_record_type
 * @property string $to_record_id
 * @property RelationCardinality $cardinality
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(['tenant_id', 'relationship_type_id', 'from_record_type', 'from_record_id', 'to_record_type', 'to_record_id', 'cardinality', 'position'])]
class RecordLink extends Model
{
    /** @use HasFactory<RecordLinkFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    protected static function booted(): void
    {
        static::creating(function (RecordLink $link): void {
            if ($link->from_record_type !== null && $link->to_record_type !== null) {
                return;
            }

            $type = RelationshipType::query()
                ->withoutGlobalScopes()
                ->whereKey($link->relationship_type_id)
                ->firstOrFail();

            $link->from_record_type ??= $type->from_object_type_id;
            $link->to_record_type ??= $type->to_object_type_id;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cardinality' => RelationCardinality::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RelationshipType, $this>
     */
    public function relationshipType(): BelongsTo
    {
        return $this->belongsTo(RelationshipType::class);
    }

    /**
     * @return BelongsTo<CustomRecord, $this>
     */
    public function fromRecord(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class, 'from_record_id');
    }

    /**
     * @return BelongsTo<CustomRecord, $this>
     */
    public function toRecord(): BelongsTo
    {
        return $this->belongsTo(CustomRecord::class, 'to_record_id');
    }
}
