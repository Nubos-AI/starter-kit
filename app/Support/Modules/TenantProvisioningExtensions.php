<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\TenantProvisioningExtensionInterface;
use App\Models\Tenant;

class TenantProvisioningExtensions
{
    /**
     * @param  iterable<TenantProvisioningExtensionInterface>  $extensions
     */
    public function __construct(private readonly iterable $extensions = []) {}

    public function provisioned(Tenant $tenant): void
    {
        foreach ($this->extensions as $extension) {
            $extension->provisioned($tenant);
        }
    }
}
