<?php

declare(strict_types=1);

namespace App\Exceptions\Maintenance;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class TenantUnderMaintenanceException extends HttpException
{
    public function __construct(
        private readonly string $tenantId,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(503, $message, $previous);
    }

    public static function writeRefused(string $tenantId): self
    {
        return new self($tenantId, __('i18n.backend.exceptions.maintenance.tenant_under_maintenance_exception.maintenance_mode_is_active_for_this_tenant_writes_are'));
    }

    public static function alreadyLocked(string $tenantId, ?Throwable $previous = null): self
    {
        return new self($tenantId, __('i18n.backend.exceptions.maintenance.tenant_under_maintenance_exception.maintenance_mode_is_already_active_for_this_tenant'), $previous);
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }
}
