<?php

declare(strict_types=1);

namespace App\Support\Export;

use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use RuntimeException;

class CsvExportWriter
{
    public function __construct(private readonly ExportCellSanitizer $sanitizer) {}

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  list<string>  $numericHeaders
     */
    public function write(string $disk, string $path, array $headers, iterable $rows, array $numericHeaders = []): int
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException(__('i18n.backend.support.export.csv_export_writer.unable_to_open_a_temporary_stream_for_the_csv'));
        }

        $numeric = array_flip($numericHeaders);

        $writer = Writer::createFromStream($stream);
        $writer->setEscape('');
        $writer->insertOne($headers);

        $rowCount = 0;

        foreach ($rows as $row) {
            $writer->insertOne(array_map(
                function (string $header) use ($row, $numeric): string {
                    $value = $this->sanitizer->stringify($row[$header] ?? null);

                    return isset($numeric[$header]) && is_numeric($value)
                        ? $value
                        : $this->sanitizer->sanitizeSpreadsheetCell($value);
                },
                $headers,
            ));

            $rowCount++;
        }

        rewind($stream);
        Storage::disk($disk)->writeStream($path, $stream);
        fclose($stream);

        return $rowCount;
    }
}
