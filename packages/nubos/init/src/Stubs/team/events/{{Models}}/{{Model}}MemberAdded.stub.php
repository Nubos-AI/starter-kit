<?php

declare(strict_types=1);

namespace App\Events\{{Models}};

use App\Models\{{Model}};
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {{Model}}MemberAdded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly {{Model}} ${{model}},
        public readonly User $user,
    ) {}
}
