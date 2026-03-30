<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\Authorization\PermissionPolicy;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UsePolicy(PermissionPolicy::class)]
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'group',
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
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
