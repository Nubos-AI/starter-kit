<?php

declare(strict_types=1);

namespace App\Support\Export;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class XlsxExportWriter
{
    public function __construct(private readonly ExportCellSanitizer $sanitizer) {}

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  list<string>  $numericHeaders
     */
    public function write(string $disk, string $path, array $headers, iterable $rows, array $numericHeaders = []): int
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $numeric = array_flip($numericHeaders);

        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($index + 1).'1',
                $header,
                DataType::TYPE_STRING,
            );
        }

        $rowNumber = 2;
        $rowCount = 0;

        foreach ($rows as $row) {
            foreach ($headers as $index => $header) {
                $coordinate = Coordinate::stringFromColumnIndex($index + 1).$rowNumber;
                $value = $this->sanitizer->stringify($row[$header] ?? null);

                if (isset($numeric[$header]) && is_numeric($value)) {
                    $sheet->setCellValueExplicit($coordinate, (float) $value, DataType::TYPE_NUMERIC);

                    continue;
                }

                $sheet->setCellValueExplicit(
                    $coordinate,
                    $this->sanitizer->sanitizeSpreadsheetCell($value),
                    DataType::TYPE_STRING,
                );
            }

            $rowNumber++;
            $rowCount++;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'export_xlsx_');

        if ($temporaryPath === false) {
            throw new RuntimeException(__('i18n.backend.support.export.xlsx_export_writer.unable_to_allocate_a_temporary_file_for_the_xlsx'));
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($temporaryPath);

            $contents = file_get_contents($temporaryPath);

            if ($contents === false) {
                throw new RuntimeException(__('i18n.backend.support.export.xlsx_export_writer.unable_to_read_the_generated_xlsx_export'));
            }

            Storage::disk($disk)->put($path, $contents);
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($temporaryPath);
        }

        return $rowCount;
    }
}
