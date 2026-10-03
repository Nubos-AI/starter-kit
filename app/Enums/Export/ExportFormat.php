<?php

declare(strict_types=1);

namespace App\Enums\Export;

enum ExportFormat: string
{
    case Csv = 'csv';

    case Xlsx = 'xlsx';

    case Json = 'json';

    public function extension(): string
    {
        return $this->value;
    }
}
