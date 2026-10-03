<?php

declare(strict_types=1);

namespace App\Enums\Authorization;

enum RoleScope: string
{
    case Platform = 'platform';

    case Tenant = 'tenant';

    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Platform => __('i18n.backend.enums.authorization.role_scope.platform'),
            self::Tenant => __('i18n.backend.enums.authorization.role_scope.tenant'),
            self::Team => __('i18n.backend.enums.authorization.role_scope.team'),
        };
    }
}
