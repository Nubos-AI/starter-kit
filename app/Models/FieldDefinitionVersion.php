<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FieldDefinitionVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string $object_type_definition_version_id
 * @property string $object_type_id
 * @property string|null $field_definition_id
 * @property string|null $snapshot_group_id
 * @property string $field_key
 * @property array<string, mixed> $snapshot
 * @property int $version_number
 * @property Carbon $changed_at
 */
#[Fillable(
    [
        'object_type_definition_version_id',
        'object_type_id',
        'field_definition_id',
        'snapshot_group_id',
        'field_key',
        'snapshot',
        'version_number',
        'changed_at',
    ]
)]
class FieldDefinitionVersion extends Model
{
    /** @use HasFactory<FieldDefinitionVersionFactory> */
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(__('i18n.backend.models.field_definition_version.field_definition_versions_is_append_only_and_cannot_be'));
        });

        static::deleting(function (): never {
            throw new RuntimeException(__('i18n.backend.models.field_definition_version.field_definition_versions_is_append_only_and_cannot_be_2'));
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
     * @return BelongsTo<ObjectTypeDefinitionVersion, $this>
     */
    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectTypeDefinitionVersion::class, 'object_type_definition_version_id');
    }
}
