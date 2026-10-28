<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ObjectTypeDefinitionVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string $object_type_id
 * @property string|null $snapshot_group_id
 * @property int $version_number
 * @property array<string, mixed> $snapshot
 * @property string|null $actor_id
 * @property string|null $change_summary
 * @property Carbon $changed_at
 */
#[Fillable(
    [
        'object_type_id',
        'snapshot_group_id',
        'version_number',
        'snapshot',
        'actor_id',
        'change_summary',
        'changed_at',
    ]
)]
class ObjectTypeDefinitionVersion extends Model
{
    /** @use HasFactory<ObjectTypeDefinitionVersionFactory> */
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(__('i18n.backend.models.object_type_definition_version.object_type_definition_versions_is_append_only_and_cannot'));
        });

        static::deleting(function (): never {
            throw new RuntimeException(__('i18n.backend.models.object_type_definition_version.object_type_definition_versions_is_append_only_and_cannot_2'));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version_number' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }

    /**
     * @return HasMany<FieldDefinitionVersion, $this>
     */
    public function fieldDefinitionVersions(): HasMany
    {
        return $this->hasMany(FieldDefinitionVersion::class);
    }
}
