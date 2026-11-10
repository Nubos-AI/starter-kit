<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use InvalidArgumentException;

class ReservedSlugException extends InvalidArgumentException
{
    public static function forSlug(string $slug): self
    {
        return new self(
            sprintf(__('i18n.backend.exceptions.engine.reserved_slug_exception.the_identifier_s_is_reserved_for_the_system_choose'), $slug),
        );
    }

    public static function forUnusableName(string $source): self
    {
        return new self(
            sprintf(
                __('i18n.backend.exceptions.engine.reserved_slug_exception.the_name_s_contains_neither_letters_nor_digits_so'),
                $source,
            ),
        );
    }

    public static function forPermissionName(string $slug, string $permission): self
    {
        return new self(
            sprintf(
                __('i18n.backend.exceptions.engine.reserved_slug_exception.the_identifier_s_would_claim_the_existing_permission_s'),
                $slug,
                $permission,
            ),
        );
    }
}
