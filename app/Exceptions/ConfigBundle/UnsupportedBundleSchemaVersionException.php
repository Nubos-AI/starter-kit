<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use RuntimeException;

class UnsupportedBundleSchemaVersionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $foundVersion,
        public readonly int $supportedVersion,
        public readonly ?int $missingVersion = null,
    ) {
        parent::__construct($message);
    }

    public static function newerThanSupported(int $foundVersion, int $supportedVersion): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.unsupported_bundle_schema_version_exception.this_bundle_was_created_with_a_newer_version_than', ['value1' => $foundVersion, 'value2' => $supportedVersion]),
            $foundVersion,
            $supportedVersion,
        );
    }

    public static function invalidVersion(int $foundVersion, int $supportedVersion): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.unsupported_bundle_schema_version_exception.this_bundle_does_not_specify_a_valid_schema_version', ['value1' => $foundVersion, 'value2' => $supportedVersion]),
            $foundVersion,
            $supportedVersion,
        );
    }

    public static function chainGap(int $foundVersion, int $missingVersion, int $supportedVersion): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.unsupported_bundle_schema_version_exception.this_bundle_is_at_version_and_cannot_be_upgraded', ['value1' => $foundVersion, 'value2' => $supportedVersion, 'value3' => $missingVersion]),
            $foundVersion,
            $supportedVersion,
            $missingVersion,
        );
    }
}
