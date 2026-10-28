<?php

declare(strict_types=1);

namespace App\Enums\Promotion;

enum PromotionDirection: string
{
    case TenantToProduction = 'sandbox_to_production';

    case BundleImport = 'bundle_import';
}
