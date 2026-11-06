<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Contracts\Tenancy\PublicSiteProviderInterface;
use App\DTOs\Tenancy\PublicSitePageData;
use App\DTOs\Tenancy\SiteAddress;
use App\Models\User;

class PublicSiteProviders
{
    /**
     * @param  iterable<PublicSiteProviderInterface>  $providers
     */
    public function __construct(private readonly iterable $providers = []) {}

    public function pageOf(SiteAddress $address, ?User $visitor): ?PublicSitePageData
    {
        foreach ($this->providers as $provider) {
            $page = $provider->pageOf($address, $visitor);

            if ($page !== null) {
                return $page;
            }
        }

        return null;
    }

    public function siteOfDomain(string $host): ?SiteAddress
    {
        foreach ($this->providers as $provider) {
            $site = $provider->siteOfDomain($host);

            if ($site !== null) {
                return $site;
            }
        }

        return null;
    }

    public function claimsDomain(string $host): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->claimsDomain($host)) {
                return true;
            }
        }

        return false;
    }
}
