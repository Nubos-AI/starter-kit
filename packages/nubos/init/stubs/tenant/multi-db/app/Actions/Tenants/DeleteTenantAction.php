<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Events\Tenants\TenantDeleted;
use App\Models\Tenant;
use App\Services\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;

class DeleteTenantAction
{
    public function __construct(
        private readonly TenantDatabaseManager $databaseManager,
    ) {
    }

    public function execute(Tenant $tenant): void
    {
        $this->databaseManager->dropDatabase($tenant);

        DB::transaction(function () use ($tenant): void {
            $tenant->members()->detach();
            $tenant->delete();
        });

        TenantDeleted::dispatch($tenant);
    }
}
