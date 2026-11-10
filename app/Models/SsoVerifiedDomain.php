<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\SsoVerifiedDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $sso_connection_id
 * @property string $domain
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'sso_connection_id',
        'domain',
    ]
)]
class SsoVerifiedDomain extends Model
{
    /** @use HasFactory<SsoVerifiedDomainFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<SsoConnection, $this>
     */
    public function ssoConnection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class);
    }

    /**
     * @param  Builder<SsoVerifiedDomain>  $query
     */
    #[Scope]
    protected function claimedOutside(Builder $query, string $tenantId, string $domain): void
    {
        $query
            ->where('sso_verified_domains.tenant_id', '!=', $tenantId)
            ->where('sso_verified_domains.domain', $domain);
    }
}
