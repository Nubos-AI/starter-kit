<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\Import\ImportColumnFormat;
use Carbon\CarbonImmutable;
use Throwable;

class ColumnFormatDetector
{
    /**
     * @var list<string>
     */
    private array $dateFormats = [
        'd.m.Y',
        'd/m/Y',
        'Y-m-d',
        'd.m.Y H:i',
        'd.m.Y H:i:s',
        'Y-m-d H:i',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i',
        'Y-m-d\TH:i:s',
    ];

    /**
     * @param  array<int, string|null>  $samples
     */
    public function detectColumn(array $samples): ImportColumnFormat
    {
        $values = array_values(array_filter(
            array_map(static fn (?string $value): string => trim((string) $value), $samples),
            static fn (string $value): bool => $value !== '',
        ));

        if ($values === []) {
            return ImportColumnFormat::Text;
        }

        $allDates = true;
        $allNumeric = true;
        $anyDecimal = false;

        foreach ($values as $value) {
            if ($this->looksLikeDate($value)) {
                $allNumeric = false;

                continue;
            }

            $allDates = false;

            $numeric = $this->parseNumericString($value);

            if ($numeric === null) {
                $allNumeric = false;

                continue;
            }

            if (str_contains($numeric, '.')) {
                $anyDecimal = true;
            }
        }

        if ($allDates) {
            return ImportColumnFormat::Date;
        }

        if ($allNumeric) {
            return $anyDecimal ? ImportColumnFormat::Decimal : ImportColumnFormat::Integer;
        }

        return ImportColumnFormat::Text;
    }

    public function normalize(?string $raw, ImportColumnFormat $format): mixed
    {
        $value = $raw === null ? '' : trim($raw);

        if ($value === '') {
            return null;
        }

        return match ($format) {
            ImportColumnFormat::Integer => $this->normalizeInteger($value),
            ImportColumnFormat::Decimal, ImportColumnFormat::Money => $this->normalizeDecimal($value),
            ImportColumnFormat::Date => $this->normalizeDate($value, 'Y-m-d'),
            ImportColumnFormat::DateTime => $this->normalizeDate($value, 'Y-m-d H:i:s'),
            ImportColumnFormat::Boolean => $this->normalizeBoolean($value),
            ImportColumnFormat::MultiSelect => $this->normalizeMultiSelect($value),
            default => $value,
        };
    }

    private function normalizeInteger(string $value): mixed
    {
        $numeric = $this->parseNumericString($value);

        if ($numeric === null) {
            return $value;
        }

        return (int) round((float) $numeric);
    }

    private function normalizeDecimal(string $value): mixed
    {
        return $this->parseNumericString($value) ?? $value;
    }

    private function normalizeDate(string $value, string $format): string
    {
        $parsed = $this->parseDate($value);

        return $parsed?->format($format) ?? $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeMultiSelect(string $value): array
    {
        $decoded = json_decode($value, true);

        $parts = is_array($decoded)
            ? array_map(static fn (mixed $entry): string => is_scalar($entry) ? (string) $entry : '', $decoded)
            : explode(',', $value);

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function normalizeBoolean(string $value): mixed
    {
        $truthy = ['1', 'true', 'yes', 'ja', 'wahr', 'x'];
        $falsy = ['0', 'false', 'no', 'nein', 'falsch'];
        $lower = mb_strtolower($value);

        if (in_array($lower, $truthy, true)) {
            return true;
        }

        if (in_array($lower, $falsy, true)) {
            return false;
        }

        return $value;
    }

    private function looksLikeDate(string $value): bool
    {
        if (preg_match('/^\d{1,2}[.\/]\d{1,2}[.\/]\d{4}([ T]\d{1,2}:\d{2}(:\d{2})?)?$/', $value) === 1) {
            return true;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{1,2}:\d{2}(:\d{2})?)?$/', $value) === 1;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        foreach ($this->dateFormats as $candidate) {
            $parsed = $this->parseWithFormat($value, $candidate);

            if ($parsed instanceof CarbonImmutable) {
                return $parsed;
            }
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseWithFormat(string $value, string $format): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
        } catch (Throwable) {
            return null;
        }

        if (!$parsed instanceof CarbonImmutable || $parsed->format($format) !== $value) {
            return null;
        }

        return $parsed;
    }

    private function parseNumericString(string $value): ?string
    {
        $cleaned = preg_replace('/[^0-9,.\-]/', '', $value);

        if ($cleaned === null || $cleaned === '' || $cleaned === '-') {
            return null;
        }

        $lastComma = strrpos($cleaned, ',');
        $lastDot = strrpos($cleaned, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $cleaned = str_replace(['.', ','], ['', '.'], $cleaned);
            } else {
                $cleaned = str_replace(',', '', $cleaned);
            }
        } elseif ($lastComma !== false) {
            $cleaned = str_replace(',', '.', $cleaned);
        }

        return is_numeric($cleaned) ? $cleaned : null;
    }
}
