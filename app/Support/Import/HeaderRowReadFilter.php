<?php

declare(strict_types=1);

namespace App\Support\Import;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class HeaderRowReadFilter implements IReadFilter
{
    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row === 1;
    }
}
