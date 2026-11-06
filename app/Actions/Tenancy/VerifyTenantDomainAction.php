<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\OwnDomains;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VerifyTenantDomainAction
{
    public function __construct(
        private readonly WelcomeTenantResolver $welcome,
        private readonly OwnDomains $ownDomains,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(Tenant $tenant): Tenant
    {
        $domain = $tenant->domain;

        if ($domain === null) {
            throw $this->ownDomains->missing();
        }

        $target = $this->welcome->subdomainOf($tenant);
        $isPointing = $this->ownDomains->isPointing($domain, $target, ['tenant_id' => $tenant->getKey()]);

        $tenant->fill(['domain_verified_at' => $isPointing ? now() : null]);

        if ($tenant->isDirty()) {
            $tenant->save();
        }

        Log::info('Tenant welcome domain verified.', ['tenant_id' => $tenant->getKey(), 'is_pointing' => $isPointing]);

        if (!$isPointing) {
            throw $this->ownDomains->notPointing($target);
        }

        return $tenant;
    }
}
