<?php

declare(strict_types=1);

namespace App\Exceptions\Scim;

use App\Enums\Scim\ScimErrorType;
use RuntimeException;

class ScimException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?ScimErrorType $scimType,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function invalidFilter(): self
    {
        return new self(400, ScimErrorType::InvalidFilter, __('i18n.backend.exceptions.scim.scim_exception.invalid_filter'));
    }

    public static function invalidSyntax(): self
    {
        return new self(400, ScimErrorType::InvalidSyntax, __('i18n.backend.exceptions.scim.scim_exception.invalid_syntax'));
    }

    public static function invalidValue(string $detail): self
    {
        return new self(400, ScimErrorType::InvalidValue, $detail);
    }

    public static function uniqueness(): self
    {
        return new self(409, ScimErrorType::Uniqueness, __('i18n.backend.exceptions.scim.scim_exception.uniqueness'));
    }

    public static function mutability(string $detail): self
    {
        return new self(400, ScimErrorType::Mutability, $detail);
    }

    public static function notFound(): self
    {
        return new self(404, null, __('i18n.backend.exceptions.scim.scim_exception.not_found'));
    }

    public static function unauthorized(): self
    {
        return new self(401, null, __('i18n.backend.exceptions.scim.scim_exception.unauthorized'));
    }
}
