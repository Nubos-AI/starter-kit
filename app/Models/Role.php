<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\Authorization\RolePolicy;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UsePolicy(RolePolicy::class)]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'scope',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }
}
