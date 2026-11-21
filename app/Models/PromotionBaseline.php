<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\PromotionBaselineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $counterpart_key
 * @property string $artifact_key
 * @property string $hash
 * @property Carbon $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'counterpart_key',
        'artifact_key',
        'hash',
        'synced_at',
    ]
)]
class PromotionBaseline extends Model
{
    /** @use HasFactory<PromotionBaselineFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
