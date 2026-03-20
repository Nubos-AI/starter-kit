<?php

declare(strict_types=1);

namespace App\Actions\{{Models}};

use App\Events\{{Models}}\{{Model}}MemberAdded;
use App\Models\{{Model}};
use App\Models\User;

class Add{{Model}}MemberAction
{
    public function execute({{Model}} ${{model}}, User $user, ?string $role = null): void
    {
        ${{model}}->users()->attach($user->id, ['role' => $role]);

        event(new {{Model}}MemberAdded(${{model}}, $user));
    }
}
