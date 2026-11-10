<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\Skills\SkillPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(SkillPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'name',
    ]
)]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
