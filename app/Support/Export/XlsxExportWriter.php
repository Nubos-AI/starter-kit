<?php

declare(strict_types=1);

namespace App\Support\Export;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use XMLWriter;
use ZipArchive;

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
        $temporaryPath = tempnam(sys_get_temp_dir(), 'export_xlsx_');

        if ($temporaryPath === false) {
            throw new RuntimeException(__('i18n.backend.support.export.xlsx_export_writer.unable_to_allocate_a_temporary_file_for_the_xlsx'));
        }

        $worksheetPath = $temporaryPath.'.xml';
        $stringsPath = $temporaryPath.'.strings.xml';

        try {
            $rowCount = $this->writeWorksheet($worksheetPath, $stringsPath, $headers, $rows, $numericHeaders);
            $this->package($temporaryPath, $worksheetPath, $stringsPath);
            $stream = fopen($temporaryPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException(__('i18n.backend.support.export.xlsx_export_writer.unable_to_read_the_generated_xlsx_export'));
            }

            try {
                if (!Storage::disk($disk)->writeStream($path, $stream)) {
                    throw new RuntimeException('Unable to store the generated XLSX export.');
                }
            } finally {
                fclose($stream);
            }

            return $rowCount;
        } finally {
            @unlink($worksheetPath);
            @unlink($stringsPath);
            @unlink($temporaryPath);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  list<string>  $numericHeaders
     */
    private function writeWorksheet(string $path, string $stringsPath, array $headers, iterable $rows, array $numericHeaders): int
    {
        $xml = new XMLWriter;
        $strings = new XMLWriter;

        if (!$xml->openUri($path) || !$strings->openUri($stringsPath)) {
            throw new RuntimeException('Unable to open the XLSX worksheet stream.');
        }

        $strings->startDocument('1.0', 'UTF-8', 'yes');
        $strings->startElementNS(null, 'sst', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $stringIndex = 0;
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetData');
        $this->writeRow($xml, $strings, 1, $headers, [], $stringIndex);
        $numeric = array_fill_keys($numericHeaders, true);
        $numericColumns = array_map(static fn (string $header): bool => isset($numeric[$header]), $headers);
        $rowCount = 0;

        foreach ($rows as $row) {
            $values = array_map(fn (string $header): string => $this->sanitizer->stringify($row[$header] ?? null), $headers);
            $this->writeRow($xml, $strings, $rowCount + 2, $values, $numericColumns, $stringIndex, sanitize: true);
            $strings->flush();
            $xml->flush();
            $rowCount++;
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();

        $strings->endElement();
        $strings->endDocument();
        $strings->flush();

        return $rowCount;
    }

    /**
     * @param  list<string>  $values
     * @param  list<bool>  $numericColumns
     */
    private function writeRow(XMLWriter $xml, XMLWriter $strings, int $number, array $values, array $numericColumns, int &$stringIndex, bool $sanitize = false): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $number);

        foreach ($values as $index => $value) {
            $xml->startElement('c');
            $xml->writeAttribute('r', Coordinate::stringFromColumnIndex($index + 1).$number);

            if (($numericColumns[$index] ?? false) && is_numeric($value) && is_finite((float) $value)) {
                $xml->writeAttribute('t', 'n');
                $xml->writeElement('v', (string) (float) $value);
            } else {
                $xml->writeAttribute('t', 's');
                $xml->writeElement('v', (string) $stringIndex++);
                $strings->startElement('si');
                $strings->startElement('t');
                $strings->writeAttribute('xml:space', 'preserve');
                $text = $sanitize ? $this->sanitizer->sanitizeSpreadsheetCell($value) : $value;
                $strings->text(StringHelper::controlCharacterPHP2OOXML((string) DataType::checkString($text)));
                $strings->endElement();
                $strings->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
    }

    private function package(string $path, string $worksheetPath, string $stringsPath): void
    {
        $spreadsheet = new Spreadsheet;

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            throw new RuntimeException('Unable to open the XLSX export archive.');
        }

        try {
            if (!$archive->addFile($worksheetPath, 'xl/worksheets/sheet1.xml')
                || !$archive->addFile($stringsPath, 'xl/sharedStrings.xml')) {
                throw new RuntimeException('Unable to package the XLSX worksheet.');
            }
        } finally {
            if (!$archive->close()) {
                throw new RuntimeException('Unable to finish the XLSX export archive.');
            }
        }
    }
}
