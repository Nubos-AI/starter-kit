<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\DTOs\Tenancy\PurgeReport;
use App\Exceptions\Tenancy\TenantPurgeFailedException;
use App\Models\CustomRecord;
use App\Models\Tenant;
use App\Scopes\TeamRecordAccessScope;
use App\Support\Tenancy\ArtifactPurgeOrder;
use App\Support\Tenancy\TenantBinder;
use App\Support\Tenancy\TenantContentPurger;
use Illuminate\Database\Eloquent\Collection;

class PurgeTenantAction
{
    public function __construct(
        private readonly TenantContentPurger $purger,
        private readonly ArtifactPurgeOrder $purgeOrder,
        private readonly TenantBinder $tenantBinder,
    ) {}

    /**
     * @throws TenantPurgeFailedException
     */
    public function execute(Tenant $tenant): PurgeReport
    {
        $this->tenantBinder->runWith($tenant, static function (): void {
            CustomRecord::query()
                ->withoutGlobalScopes([TeamRecordAccessScope::class])
                ->chunkById(
                    (int) config('scout.chunk.unsearchable', 500),
                    static function (Collection $records): void {
                        (new CustomRecord)->queueRemoveFromSearch($records);
                    },
                );
        });

        $report = $this->purger->purge((string) $tenant->getKey(), $this->purgeOrder->full());

        $tenant->forceDelete();

        return $report;
    }
}
