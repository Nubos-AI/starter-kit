<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use Temporal\Common\RetryOptions;

class MaintenanceRetryOptions
{
    public static function make(): RetryOptions
    {
        return RetryOptions::new()->withNonRetryableExceptions([TenantUnderMaintenanceException::class]);
    }
}
