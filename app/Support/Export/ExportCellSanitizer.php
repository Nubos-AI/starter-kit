<?php

declare(strict_types=1);

namespace App\Support\Export;

class ExportCellSanitizer
{
    /**
     * @var list<string>
     */
    private array $dangerousPrefixes = ['=', '+', '-', '@', "\t", "\r"];

    public function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    public function sanitizeSpreadsheetCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (in_array($value[0], $this->dangerousPrefixes, true)) {
            return "'".$value;
        }

        return $value;
    }
}
