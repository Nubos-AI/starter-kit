<?php

declare(strict_types=1);

namespace App\Contracts\Tenancy;

use App\DTOs\Tenancy\PublicSitePageData;
use App\DTOs\Tenancy\SiteAddress;
use App\Models\User;

interface PublicSiteProviderInterface
{
    public function pageOf(SiteAddress $address, ?User $visitor): ?PublicSitePageData;

    public function siteOfDomain(string $host): ?SiteAddress;

    public function claimsDomain(string $host): bool;
}
