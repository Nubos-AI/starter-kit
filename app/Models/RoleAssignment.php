<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class RoleAssignment extends MorphPivot
{
    use HasUuids;

    protected $table = 'role_assignments';

    public $incrementing = false;

    protected $keyType = 'string';
}
