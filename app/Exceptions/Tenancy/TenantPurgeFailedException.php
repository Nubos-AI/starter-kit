<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\DTOs\Tenancy\PurgeReport;
use RuntimeException;
use Throwable;

class TenantPurgeFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly PurgeReport $report,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
