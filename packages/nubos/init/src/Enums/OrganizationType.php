<?php

declare(strict_types=1);

namespace Nubos\Init\Enums;

enum OrganizationType: string
{
    case Team = 'Team';
    case Workspace = 'Workspace';
    case Tenant = 'Tenant';
}
