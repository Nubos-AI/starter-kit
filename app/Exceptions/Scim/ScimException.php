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

    public static function externalIdTaken(): self
    {
        return new self(409, ScimErrorType::Uniqueness, __('i18n.backend.exceptions.scim.scim_exception.external_id_taken'));
    }

    public static function mutability(string $detail): self
    {
        return new self(400, ScimErrorType::Mutability, $detail);
    }

    public static function privilegedAccount(): self
    {
        return self::mutability(__('i18n.backend.exceptions.scim.scim_exception.privileged_account'));
    }

    public static function beyondTokenCreator(): self
    {
        return self::mutability(__('i18n.backend.exceptions.scim.scim_exception.beyond_token_creator'));
    }

    public static function teamBeyondTokenCreator(): self
    {
        return self::mutability(__('i18n.backend.exceptions.scim.scim_exception.team_beyond_token_creator'));
    }

    public static function teamChangeBeyondTokenCreator(): self
    {
        return self::mutability(__('i18n.backend.exceptions.scim.scim_exception.team_change_beyond_token_creator'));
    }

    public static function tokenWithoutCreator(): self
    {
        return self::mutability(__('i18n.backend.exceptions.scim.scim_exception.token_without_creator'));
    }

    public static function tokenCreatorNotEntitled(): self
    {
        return new self(403, null, __('i18n.backend.exceptions.scim.scim_exception.token_without_creator'));
    }

    public static function defaultRoleBeyondTokenCreator(): self
    {
        return self::mutability(__('i18n.backend.exceptions.scim.scim_exception.default_role_beyond_token_creator'));
    }

    public static function emailOutsideSsoDomains(): self
    {
        return self::invalidValue(__('i18n.backend.exceptions.scim.scim_exception.email_outside_sso_domains'));
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
