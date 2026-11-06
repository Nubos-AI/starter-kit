<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([TenantScope::class])]
class TenantRecord extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'tenant_records';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'team_id', 'owner_id', 'title'];
}
