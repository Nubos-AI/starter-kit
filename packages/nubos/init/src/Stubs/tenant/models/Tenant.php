<?php

declare(strict_types=1);

namespace App\Models;

// @nubos:inject-imports
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;
    // @nubos:inject-traits

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'name',
        'slug',
        'owner_id',
    ];
    protected $hidden = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    protected function casts(): array
    {
        return [];
    }
}
