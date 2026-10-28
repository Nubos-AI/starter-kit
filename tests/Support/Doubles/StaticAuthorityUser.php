<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;

class StaticAuthorityUser extends User
{
    public bool $escalated = false;

    protected $table = 'users';

    public function isEscalatedAuthority(): bool
    {
        return $this->escalated;
    }
}
