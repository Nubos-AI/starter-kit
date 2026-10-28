<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\Import\ImportMappingPresetPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ImportMappingPresetFactory;
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
 * @property string $object_type_id
 * @property string|null $user_id
 * @property string $name
 * @property array<string, mixed> $mapping
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(ImportMappingPresetPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'user_id',
        'name',
        'mapping',
    ]
)]
class ImportMappingPreset extends Model
{
    /** @use HasFactory<ImportMappingPresetFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

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
            'mapping' => 'array',
        ];
    }
}
