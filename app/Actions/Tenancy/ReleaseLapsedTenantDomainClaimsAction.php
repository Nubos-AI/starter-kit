<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\Tenancy\DomainPointing;
use App\Models\Tenant;
use App\Support\Tenancy\OwnDomains;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReleaseLapsedTenantDomainClaimsAction
{
    public function __construct(
        private readonly OwnDomains $ownDomains,
        private readonly WelcomeTenantResolver $welcome,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(string $domain, ?string $exceptTenantId = null): bool
    {
        $holders = Tenant::query()
            ->verifiedDomain($domain)
            ->when($exceptTenantId !== null, static fn (Builder $query): Builder => $query->whereKeyNot($exceptTenantId))
            ->get();

        foreach ($holders as $holder) {
            $holderId = (string) $holder->getKey();
            $pointing = $this->ownDomains->pointingOf($domain, $this->welcome->subdomainOf($holder), $holderId, ['tenant_id' => $holderId]);

            if ($pointing === DomainPointing::Pointing) {
                Log::info('A verified own domain claim still verifies and was kept.', ['domain' => $domain, 'held_by_tenant_id' => $holderId]);

                return false;
            }
        }

        foreach ($holders as $holder) {
            Tenant::query()->whereKey($holder->getKey())->update(['domain' => null, 'domain_verified_at' => null]);

            Log::info('A lapsed verified own domain claim was superseded.', ['domain' => $domain, 'released_tenant_id' => (string) $holder->getKey()]);
        }

        return true;
    }
}
