<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeamUser extends Pivot
{
    use HasUuids;
    use SoftDeletes;
    use UsesTenantConnection;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'team_user';

    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'user_id',
        'role',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
