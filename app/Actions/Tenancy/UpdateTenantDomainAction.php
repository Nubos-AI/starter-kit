<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\OwnDomains;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateTenantDomainAction
{
    public function __construct(private readonly OwnDomains $ownDomains) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function execute(Tenant $tenant, array $data): Tenant
    {
        $domain = $this->ownDomains->normalize($data['domain'] ?? null);

        $tenant->fill(['domain' => $domain]);

        if ($tenant->isDirty('domain')) {
            $tenant->fill(['domain_verified_at' => null]);
        }

        $tenant->save();

        Log::info('Tenant welcome domain updated.', ['tenant_id' => $tenant->getKey(), 'has_domain' => $domain !== null]);

        return $tenant;
    }
}
