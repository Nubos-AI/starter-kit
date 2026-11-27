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
    public function __construct(
        private readonly iterable $extensions = [],
        private readonly ?ModuleAccess $access = null,
    ) {}

    public function provisioned(Tenant $tenant): void
    {
        foreach ($this->extensions as $extension) {
            if ($this->access?->allowsFor($extension, $tenant) ?? true) {
                $extension->provisioned($tenant);
            }
        }
    }
}
