<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Traits\Tenancy\TenantAware;

class LeakProbeJob
{
    use TenantAware;

    public static ?int $observedCount = null;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(): void
    {
        self::$observedCount = TenantRecord::query()->count();
    }
}
