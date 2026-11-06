<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use RuntimeException;
use Throwable;

class MalformedBundleException extends RuntimeException
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(
        string $message,
        public readonly ?string $location = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function parseFailed(string $relativePath, Throwable $previous): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_file_in_this_bundle_cannot_be_read_as', ['value1' => $relativePath]),
            $relativePath,
            [],
            $previous,
        );
    }

    public static function notAMapping(string $relativePath): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_file_in_this_bundle_is_empty_or_does', ['value1' => $relativePath]),
            $relativePath,
        );
    }

    public static function missingManifest(string $relativePath): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.this_bundle_is_missing_the_file_neither_its_schema', ['value1' => $relativePath]),
            $relativePath,
        );
    }

    public static function missingBusinessKey(string $relativePath): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_file_in_this_bundle_does_not_specify_a', ['value1' => $relativePath]),
            $relativePath,
        );
    }

    public static function unexpectedEntry(string $relativePath): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_entry_does_not_belong_in_a_bundle_each', ['value1' => $relativePath]),
            $relativePath,
        );
    }

    /**
     * @param  list<string>  $unknownKeys
     * @param  list<string>  $missingKeys
     */
    public static function manifestKeySetMismatch(string $relativePath, array $unknownKeys, array $missingKeys): self
    {
        $clauses = [];

        if ($unknownKeys !== []) {
            $clauses[] = __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_file_contains_unknown_properties', ['value1' => $relativePath]).implode(', ', $unknownKeys).')';
        }

        if ($missingKeys !== []) {
            $clauses[] = $clauses === []
                ? __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_file_is_missing_properties', ['value1' => $relativePath]).implode(', ', $missingKeys).')'
                : __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_following_details_are_missing').implode(', ', $missingKeys).')';
        }

        return new self(
            implode(' und ', $clauses).__('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.please_use_an_unmodified_bundle'),
            $relativePath,
            array_merge($unknownKeys, $missingKeys),
        );
    }

    public static function unrepresentableValue(string $payloadPath, string $type): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.malformed_bundle_exception.the_value_at_has_type_and_cannot_be_written', ['value1' => $payloadPath, 'value2' => $type]),
            $payloadPath,
            [$type],
        );
    }
}
