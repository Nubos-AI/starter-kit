<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\Segments\SegmentPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\SegmentFactory;
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
 * @property string $owner_id
 * @property string $name
 * @property string|null $object_type_id
 * @property array<string, mixed>|null $filter_definition
 * @property bool $is_system
 * @property bool $is_default
 * @property array<string, string>|null $i18n_labels
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(SegmentPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'owner_id',
        'name',
        'object_type_id',
        'filter_definition',
        'is_system',
        'is_default',
        'i18n_labels',
    ]
)]
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (Segment $segment): void {
            self::guardSystemSegment($segment);
        });

        static::deleting(function (Segment $segment): void {
            self::guardSystemSegment($segment);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

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
            'filter_definition' => 'array',
            'i18n_labels' => 'array',
            'is_system' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    private static function guardSystemSegment(Segment $segment): void
    {
        if ($segment->getOriginal('is_system')) {
            throw new AuthorizationException(__('i18n.backend.models.segment.system_segments_cannot_be_modified_or_deleted'));
        }
    }
}
