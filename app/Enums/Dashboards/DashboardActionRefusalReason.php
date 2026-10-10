<?php

declare(strict_types=1);

namespace App\Enums\Dashboards;

enum DashboardActionRefusalReason: string
{
    case NotOwner = 'not_owner';

    case NotVisible = 'not_visible';
}
