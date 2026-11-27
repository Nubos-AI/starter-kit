<?php

declare(strict_types=1);

namespace App\Enums\Users;

enum RegistrationReleaseReason: string
{
    case Expired = 'expired';

    case ClaimedBySingleSignOn = 'claimed_by_single_sign_on';

    case ClaimedByScim = 'claimed_by_scim';

    case ClaimedByInvitation = 'claimed_by_invitation';
}
