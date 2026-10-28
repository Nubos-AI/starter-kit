<?php

declare(strict_types=1);

namespace App\Exceptions\Promotion;

use RuntimeException;

class PromotionSourceUnavailableException extends RuntimeException
{
    protected function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function moduleMissing(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_source_unavailable_exception.the_module_required_for_this_transfer_is_not_installed'));
    }

    public static function withoutSourceTenant(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_source_unavailable_exception.no_source_tenant_is_set_for_this_promotion_nothing'));
    }

    public static function sourceTenantMissing(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_source_unavailable_exception.this_promotion_s_source_tenant_no_longer_exists_nothing'));
    }

    public static function bundleDirectoryMissing(): self
    {
        return new self(__('i18n.backend.exceptions.promotion.promotion_source_unavailable_exception.no_package_was_stored_for_this_configuration_import_nothing'));
    }
}
