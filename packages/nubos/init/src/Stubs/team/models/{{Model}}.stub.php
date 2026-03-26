<?php

declare(strict_types=1);

namespace App\Models;

// @nubos:inject-imports
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class {{Model}} extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;
    // @nubos:inject-traits

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'personal_{{model}}',
    ];

    protected function casts(): array
    {
        return [
            'personal_{{model}}' => 'boolean',
        ];
    }

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
}
