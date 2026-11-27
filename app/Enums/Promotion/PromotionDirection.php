<?php

declare(strict_types=1);

namespace App\Enums\Promotion;

enum PromotionDirection: string
{
    case TenantToProduction = 'tenant_to_production';

    case BundleImport = 'bundle_import';
}
