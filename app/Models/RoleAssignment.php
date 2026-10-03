<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $role_id
 * @property string $model_type
 * @property string $model_id
 * @property string|null $scope_type
 * @property string|null $scope_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoleAssignment extends MorphPivot
{
    use HasUlids;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
