<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\OwnDomains;
use App\Support\Tenancy\PublicSiteProviders;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateTenantDomainAction
{
    public function __construct(
        private readonly OwnDomains $ownDomains,
        private readonly PublicSiteProviders $sites,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function execute(Tenant $tenant, array $data): Tenant
    {
        $domain = $this->ownDomains->normalize($data['domain'] ?? null);

        if ($domain !== null && $this->isClaimedElsewhere($tenant, $domain)) {
            throw $this->ownDomains->taken();
        }

        $tenant->fill(['domain' => $domain]);

        if ($tenant->isDirty('domain')) {
            $tenant->fill(['domain_verified_at' => null]);
        }

        $tenant->save();

        Log::info('Tenant welcome domain updated.', ['tenant_id' => $tenant->getKey(), 'has_domain' => $domain !== null]);

        return $tenant;
    }

    private function isClaimedElsewhere(Tenant $tenant, string $domain): bool
    {
        return Tenant::query()->where('domain', $domain)->whereKeyNot($tenant->getKey())->exists()
            || $this->sites->claimsDomain($domain);
    }
}
