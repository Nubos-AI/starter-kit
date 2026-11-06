<?php

declare(strict_types=1);

namespace App\Exceptions\Users;

use RuntimeException;

class InvitationNotAcceptableException extends RuntimeException
{
    public static function noLongerPending(): self
    {
        return new self(__('i18n.backend.exceptions.users.invitation_not_acceptable_exception.the_invitation_is_no_longer_open_has_expired_or'));
    }
}
