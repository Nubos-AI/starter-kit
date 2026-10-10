<?php

declare(strict_types=1);

namespace App\Enums\Authorization;

enum RoleAuthority: string
{
    case SuperAdmin = 'super_admin';

    case ScopeAdmin = 'scope_admin';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => __('i18n.backend.enums.authorization.role_authority.unrestricted_administration'),
            self::ScopeAdmin => __('i18n.backend.enums.authorization.role_authority.administration_within_own_scope'),
        };
    }
}
