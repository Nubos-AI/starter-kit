<?php

declare(strict_types=1);

namespace Tests\Fixtures\Records;

use App\Attributes\Engine\BackedByObjectType;
use App\Traits\Engine\RepresentsObjectType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[BackedByObjectType('employees')]
class Employee extends Model
{
    use HasUlids;
    use RepresentsObjectType;

    protected $table = 'employees';

    /** @var list<string> */
    protected $fillable = ['full_name', 'internal_note', 'secret_token', 'manager_id', 'headcount'];

    /** @var list<string> */
    protected $hidden = ['secret_token'];

    /**
     * @return array<string, mixed>
     */
    public static function objectTypeBacking(): array
    {
        return ['title' => 'full_name', 'except' => ['internal_note']];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }
}
