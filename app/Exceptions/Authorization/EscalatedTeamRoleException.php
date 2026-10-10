<?php

declare(strict_types=1);

namespace App\Exceptions\Authorization;

use RuntimeException;

class EscalatedTeamRoleException extends RuntimeException
{
    public static function forRole(string $roleName): self
    {
        return new self(
            __('i18n.backend.exceptions.authorization.escalated_team_role_exception.the_role_has_elevated_authority_and_cannot_be_assigned', ['value1' => $roleName]),
        );
    }
}
