<?php

declare(strict_types=1);

namespace App\Actions\{{Models}};

use App\Events\{{Models}}\{{Model}}MemberRemoved;
use App\Models\{{Model}};
use App\Models\User;

class Remove{{Model}}MemberAction
{
    public function execute({{Model}} ${{model}}, User $user): void
    {
        ${{model}}->users()->detach($user->id);

        event(new {{Model}}MemberRemoved(${{model}}, $user));
    }
}
