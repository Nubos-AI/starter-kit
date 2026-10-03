<?php

declare(strict_types=1);

namespace App\Enums\Reports;

enum ReportActionRefusalReason: string
{
    case NotOwner = 'not_owner';

    case ObjectTypeNotPermitted = 'object_type_not_permitted';
}
