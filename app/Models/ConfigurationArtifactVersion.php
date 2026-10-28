<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConfigBundle\ArtifactKind;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ConfigurationArtifactVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $snapshot_group_id
 * @property ArtifactKind $artifact_kind
 * @property string $artifact_key
 * @property string|null $artifact_id
 * @property int $version_number
 * @property array<string, mixed> $snapshot
 * @property string|null $actor_id
 * @property string|null $change_summary
 * @property Carbon $changed_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'snapshot_group_id',
        'artifact_kind',
        'artifact_key',
        'artifact_id',
        'version_number',
        'snapshot',
        'actor_id',
        'change_summary',
        'changed_at',
    ]
)]
class ConfigurationArtifactVersion extends Model
{
    /** @use HasFactory<ConfigurationArtifactVersionFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(__('i18n.backend.models.configuration_artifact_version.configuration_artifact_versions_is_append_only_and_cannot_be'));
        });

        static::deleting(function (): never {
            throw new RuntimeException(__('i18n.backend.models.configuration_artifact_version.configuration_artifact_versions_is_append_only_and_cannot_be_2'));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'artifact_kind' => ArtifactKind::class,
            'snapshot' => 'array',
            'version_number' => 'integer',
            'changed_at' => 'datetime',
        ];
    }
}
