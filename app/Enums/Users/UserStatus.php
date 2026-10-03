<?php

declare(strict_types=1);

namespace App\Enums\Users;

enum UserStatus: string
{
    case Invited = 'invited';

    case Accepted = 'accepted';

    case Blocked = 'blocked';

    case Deleted = 'deleted';

    public function canAuthenticate(): bool
    {
        return $this === self::Accepted;
    }
}
