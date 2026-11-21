<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\Tenancy\DomainPointing;
use App\Models\Tenant;
use App\Support\Tenancy\OwnDomains;
use App\Support\Tenancy\PublicSiteProviders;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifyTenantDomainAction
{
    public function __construct(
        private readonly WelcomeTenantResolver $welcome,
        private readonly OwnDomains $ownDomains,
        private readonly PublicSiteProviders $sites,
        private readonly ReleaseLapsedTenantDomainClaimsAction $releaseLapsedClaims,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(Tenant $tenant): Tenant
    {
        $domain = $tenant->domain;

        if ($domain === null) {
            throw $this->ownDomains->missing();
        }

        $target = $this->welcome->subdomainOf($tenant);
        $pointing = $this->ownDomains->pointingOf($domain, $target, (string) $tenant->getKey(), ['tenant_id' => $tenant->getKey()]);
        $isPointing = $pointing === DomainPointing::Pointing;

        try {
            $releasedClaims = DB::transaction(function () use ($tenant, $domain, $isPointing): int {
                if ($isPointing && !$this->isFreeFor($tenant, $domain)) {
                    throw $this->ownDomains->taken();
                }

                $tenant->fill(['domain_verified_at' => $isPointing ? now() : null]);

                if ($tenant->isDirty()) {
                    $tenant->save();
                }

                return $isPointing ? $this->releaseUnverifiedClaims($tenant, $domain) : 0;
            });
        } catch (UniqueConstraintViolationException) {
            Log::warning('Tenant welcome domain was verified by another tenant first.', ['tenant_id' => $tenant->getKey()]);

            $tenant->fill(['domain_verified_at' => null]);

            throw $this->ownDomains->taken();
        }

        Log::info('Tenant welcome domain verified.', ['tenant_id' => $tenant->getKey(), 'is_pointing' => $isPointing, 'released_claims' => $releasedClaims]);

        if (!$isPointing) {
            throw $this->ownDomains->refusal($pointing, $domain, $target, (string) $tenant->getKey());
        }

        return $tenant;
    }

    /**
     * @throws ValidationException
     */
    private function isFreeFor(Tenant $tenant, string $domain): bool
    {
        return $this->releaseLapsedClaims->execute($domain, (string) $tenant->getKey())
            && $this->sites->releaseLapsedClaims($domain);
    }

    private function releaseUnverifiedClaims(Tenant $tenant, string $domain): int
    {
        return Tenant::query()
            ->where('domain', $domain)
            ->whereNull('domain_verified_at')
            ->whereKeyNot($tenant->getKey())
            ->update(['domain' => null]);
    }
}
