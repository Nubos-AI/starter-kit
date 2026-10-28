<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $auditable_type
 * @property string $auditable_id
 * @property string $field_key
 * @property mixed $old_value
 * @property mixed $new_value
 * @property string|null $actor_id
 * @property string|null $actor_type
 * @property int $version
 * @property Carbon $changed_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'auditable_type',
        'auditable_id',
        'field_key',
        'old_value',
        'new_value',
        'actor_id',
        'actor_type',
        'version',
        'changed_at',
    ]
)]
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(__('i18n.backend.models.audit_entry.audit_entries_is_append_only_and_cannot_be_updated'));
        });

        static::deleting(function (): never {
            throw new RuntimeException(__('i18n.backend.models.audit_entry.audit_entries_is_append_only_and_cannot_be_deleted'));
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'version' => 'integer',
            'changed_at' => 'datetime',
        ];
    }
}
