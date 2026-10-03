<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use Illuminate\Support\Str;
use RuntimeException;

class UnsafeArchiveException extends RuntimeException
{
    private static int $displayedNameLength = 120;

    public static function unreadableContainer(): self
    {
        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_uploaded_file_is_not_a_readable_zip_archive'));
    }

    public static function disallowedName(string $name): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_has_an_invalid_name_only_relative', ['value1' => $display]));
    }

    public static function disallowedExtension(string $name): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_is_not_a_yaml_file_a', ['value1' => $display]));
    }

    public static function nameTooLong(string $name, int $maxBytes): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_has_a_name_that_is_too', ['value1' => $display, 'value2' => $maxBytes]));
    }

    public static function pathTooLong(string $name): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_produces_a_path_that_is_too', ['value1' => $display]));
    }

    public static function tooDeep(string $name, int $maxDepth): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_exceeds_the_allowed_directory_levels', ['value1' => $display, 'value2' => $maxDepth]));
    }

    public static function duplicateEntry(string $name): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_occurs_more_than_once_in_the', ['value1' => $display]));
    }

    public static function fileAndDirectory(string $name): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_is_both_a_file_and_a', ['value1' => $display]));
    }

    public static function tooManyEntries(int $count, int $maxEntries): self
    {
        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_contains_entries_at_most_are_allowed', ['value1' => $count, 'value2' => $maxEntries]));
    }

    public static function totalSizeExceeded(int $maxBytes): self
    {
        $megabytes = intdiv($maxBytes, 1048576);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_extracted_archive_exceeds_the_allowed_mb', ['value1' => $megabytes]));
    }

    public static function compressionRatioExceeded(string $name): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_is_unusually_highly_compressed_and_will', ['value1' => $display]));
    }

    public static function declaredSizeMismatch(string $name): self
    {
        $display = self::display($name);

        return new self(__('i18n.backend.exceptions.config_bundle.unsafe_archive_exception.the_archive_entry_differs_from_the_size_declared_in', ['value1' => $display]));
    }

    private static function display(string $name): string
    {
        return Str::limit(str_replace("\0", '', mb_scrub($name, 'UTF-8')), self::$displayedNameLength);
    }
}
